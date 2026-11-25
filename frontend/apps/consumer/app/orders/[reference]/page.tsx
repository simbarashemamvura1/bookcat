import OrderView from './OrderView';

export default async function OrderPage({ params }: { params: Promise<{ reference: string }> }) {
  const { reference } = await params;

  return (
    <main className="mx-auto max-w-2xl px-4 py-8">
      <OrderView reference={reference} />
    </main>
  );
}