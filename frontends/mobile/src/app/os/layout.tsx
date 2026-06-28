import { AuthGuard } from '@/components/auth-guard';
import { AuthenticatedShell } from '@/components/authenticated-shell';

export default function OrdersLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <AuthGuard>
      <AuthenticatedShell>{children}</AuthenticatedShell>
    </AuthGuard>
  );
}
