// Seat positions are generated from section / row / number for now. When floor plans carry real
// coordinates (see the decision log), only this file changes.

export const SEAT = 26;
const GAP = 6;
const ROW_GAP = 8;
const SECTION_GAP = 44;
const GUTTER = 34; // room for row labels
const PAD = 16;
const STAGE_H = 28;

type LayoutSeat = { key: string; section: string; row: string | null; number: string };

export type Placed<T> = { seat: T; x: number; y: number };
export type Label = { x: number; y: number; text: string };

export type SeatLayout<T> = {
  placed: Placed<T>[];
  rowLabels: Label[];
  sectionLabels: Label[];
  stage: { x: number; y: number; width: number; height: number };
  width: number;
  height: number;
};

function seatNumber(value: string): number {
  const n = Number(value);

  return Number.isNaN(n) ? Number.POSITIVE_INFINITY : n;
}

export function layoutSeats<T extends LayoutSeat>(seats: T[]): SeatLayout<T> {
  const sections = new Map<string, Map<string, T[]>>();

  for (const seat of seats) {
    let rows = sections.get(seat.section);
    if (!rows) {
      rows = new Map();
      sections.set(seat.section, rows);
    }

    const rowKey = seat.row ?? '';
    let list = rows.get(rowKey);
    if (!list) {
      list = [];
      rows.set(rowKey, list);
    }

    list.push(seat);
  }

  const step = SEAT + GAP;
  let widest = 1;

  for (const rows of sections.values()) {
    for (const list of rows.values()) {
      list.sort((a, b) => seatNumber(a.number) - seatNumber(b.number) || a.number.localeCompare(b.number));
      widest = Math.max(widest, list.length);
    }
  }

  const innerWidth = widest * step - GAP;
  const left = PAD + GUTTER;
  const width = left + innerWidth + GUTTER + PAD;

  const placed: Placed<T>[] = [];
  const rowLabels: Label[] = [];
  const sectionLabels: Label[] = [];
  const stage = { x: left, y: PAD, width: innerWidth, height: STAGE_H };

  let y = PAD + STAGE_H + 28;

  for (const [section, rows] of sections) {
    sectionLabels.push({ x: width / 2, y: y + 10, text: section });
    y += 26;

    for (const [row, list] of rows) {
      const rowWidth = list.length * step - GAP;
      const startX = left + (innerWidth - rowWidth) / 2; // short rows are centred

      list.forEach((seat, i) => placed.push({ seat, x: startX + i * step, y }));

      if (row !== '') {
        rowLabels.push({ x: PAD + GUTTER / 2, y: y + SEAT / 2, text: row });
        rowLabels.push({ x: width - PAD - GUTTER / 2, y: y + SEAT / 2, text: row });
      }

      y += SEAT + ROW_GAP;
    }

    y += SECTION_GAP - ROW_GAP;
  }

  return { placed, rowLabels, sectionLabels, stage, width, height: y - SECTION_GAP + PAD };
}