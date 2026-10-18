<?php

namespace App\Services;

/**
 * Lua scripts for seat holds. Redis runs each script as one uninterrupted unit,
 * which is what makes "take all these seats or none" safe under concurrent buyers.
 *
 * KEYS layout for every script that touches seats:
 *   KEYS[1] = the buyer's cart (hash: deadline, state, one 's:<seat>' field per seat)
 *   KEYS[2] = the event's held index (sorted set: seat => expiry in ms)
 *   KEYS[3..] = one lock key per seat (value = the cart token, expires with the hold)
 */
final class SeatHoldScripts
{
    private const NOW = <<<'LUA'
local t = redis.call('TIME')
local now = tonumber(t[1]) * 1000 + math.floor(tonumber(t[2]) / 1000)

LUA;

    /**
     * ARGV: token, window_ms, max_seats, seat1..n (matching KEYS[3..]).
     * Returns {1, deadline, remaining} | {0, conflictSeat...} | {-1} cart locked for payment | {-2} too many seats.
     */
    public const SELECT = self::NOW . <<<'LUA'
local cart = KEYS[1]
local held = KEYS[2]
local token = ARGV[1]
local window = tonumber(ARGV[2])
local maxSeats = tonumber(ARGV[3])

local deadline = tonumber(redis.call('HGET', cart, 'deadline'))
if deadline and deadline <= now then
  redis.call('DEL', cart)
  deadline = nil
end
if deadline and redis.call('HGET', cart, 'state') == 'paying' then
  return {-1}
end

local owned = {}
local ownedCount = 0
if deadline then
  for _, f in ipairs(redis.call('HKEYS', cart)) do
    if string.sub(f, 1, 2) == 's:' then
      owned[string.sub(f, 3)] = true
      ownedCount = ownedCount + 1
    end
  end
end

local conflicts = {}
local newCount = 0
for i = 3, #KEYS do
  local seat = ARGV[i + 1]
  local holder = redis.call('GET', KEYS[i])
  if holder and holder ~= token then
    conflicts[#conflicts + 1] = seat
  end
  if not owned[seat] then
    newCount = newCount + 1
  end
end

if #conflicts > 0 then
  return {0, unpack(conflicts)}
end
if ownedCount + newCount > maxSeats then
  return {-2}
end

if not deadline then
  deadline = now + window
  redis.call('HSET', cart, 'deadline', string.format('%.0f', deadline), 'state', 'selecting')
end
local remaining = deadline - now

redis.call('ZREMRANGEBYSCORE', held, '-inf', string.format('%.0f', now))
for i = 3, #KEYS do
  local seat = ARGV[i + 1]
  redis.call('SET', KEYS[i], token, 'PX', string.format('%.0f', remaining))
  redis.call('HSET', cart, 's:' .. seat, 1)
  redis.call('ZADD', held, string.format('%.0f', deadline), seat)
end
redis.call('PEXPIRE', cart, string.format('%.0f', remaining))
redis.call('PEXPIRE', held, 86400000)

return {1, deadline, remaining}
LUA;

    /**
     * ARGV: token, allow_paying ('1'|'0'), seat1..n (matching KEYS[3..]).
     * Only releases seats this token actually holds.
     * Returns {1, released, seatsLeft} | {-1} cart locked for payment.
     */
    public const DESELECT = <<<'LUA'
local cart = KEYS[1]
local held = KEYS[2]
local token = ARGV[1]
local allowPaying = ARGV[2] == '1'

if (not allowPaying) and redis.call('HGET', cart, 'state') == 'paying' then
  return {-1}
end

local released = 0
for i = 3, #KEYS do
  local seat = ARGV[i]
  if redis.call('GET', KEYS[i]) == token then
    redis.call('DEL', KEYS[i])
    redis.call('ZREM', held, seat)
    released = released + 1
  end
  redis.call('HDEL', cart, 's:' .. seat)
end

local left = 0
for _, f in ipairs(redis.call('HKEYS', cart)) do
  if string.sub(f, 1, 2) == 's:' then
    left = left + 1
  end
end
if left == 0 then
  redis.call('DEL', cart)
end

return {1, released, left}
LUA;

    /**
     * KEYS[1] = cart. Returns {} when there is no live cart,
     * otherwise {state, deadline, remaining, seat...}.
     */
    public const STATUS = self::NOW . <<<'LUA'
local deadline = tonumber(redis.call('HGET', KEYS[1], 'deadline'))
if not deadline or deadline <= now then
  return {}
end

-- Return the deadline as a string: it is a 13-digit number, and some PHP/Windows Redis
-- clients clamp integer replies above 2^31 (we saw 2147483647).
local out = {redis.call('HGET', KEYS[1], 'state'), string.format('%.0f', deadline), deadline - now}
for _, f in ipairs(redis.call('HKEYS', KEYS[1])) do
  if string.sub(f, 1, 2) == 's:' then
    out[#out + 1] = string.sub(f, 3)
  end
end
return out
LUA;

    /**
     * ARGV: token, grace_ms, seat1..n (matching KEYS[3..]).
     * Locks the cart for payment and guarantees at least grace_ms of hold from now.
     * Idempotent: pressing Pay twice does not extend the hold twice.
     * Returns {1, deadline, ttl} | {-3} expired | {-4} seat list changed | {-5} a seat was lost.
     */
    public const BEGIN_PAYMENT = self::NOW . <<<'LUA'
local cart = KEYS[1]
local held = KEYS[2]
local token = ARGV[1]
local grace = tonumber(ARGV[2])

local deadline = tonumber(redis.call('HGET', cart, 'deadline'))
if not deadline or deadline <= now then
  return {-3}
end

local inCart = 0
for _, f in ipairs(redis.call('HKEYS', cart)) do
  if string.sub(f, 1, 2) == 's:' then
    inCart = inCart + 1
  end
end
if inCart == 0 or inCart ~= #KEYS - 2 then
  return {-4}
end

for i = 3, #KEYS do
  if redis.call('HEXISTS', cart, 's:' .. ARGV[i]) == 0 then
    return {-4}
  end
  if redis.call('GET', KEYS[i]) ~= token then
    return {-5}
  end
end

if redis.call('HGET', cart, 'state') == 'paying' then
  return {1, deadline, deadline - now}
end

local newDeadline = deadline
if now + grace > newDeadline then
  newDeadline = now + grace
end
local ttl = newDeadline - now

for i = 3, #KEYS do
  redis.call('PEXPIRE', KEYS[i], string.format('%.0f', ttl))
  redis.call('ZADD', held, string.format('%.0f', newDeadline), ARGV[i])
end
redis.call('HSET', cart, 'state', 'paying', 'deadline', string.format('%.0f', newDeadline))
redis.call('PEXPIRE', cart, string.format('%.0f', ttl))

return {1, newDeadline, ttl}
LUA;

    /** KEYS[1] = cart. Puts a paying cart back to selecting (payment failed or was cancelled). */
    public const ABORT_PAYMENT = <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 1 then
  redis.call('HSET', KEYS[1], 'state', 'selecting')
  return 1
end
return 0
LUA;

    /** KEYS[1] = held index. Returns a flat list: seat, expiresAtMs, seat, expiresAtMs... */
    public const AVAILABILITY = self::NOW . <<<'LUA'
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', string.format('%.0f', now))
return redis.call('ZRANGEBYSCORE', KEYS[1], string.format('(%.0f', now), '+inf', 'WITHSCORES')
LUA;
}