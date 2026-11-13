import { router } from '@inertiajs/react';
import { Check, ShieldAlert, X } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { ago } from '@/lib/computers';
import { approve, decline } from '@/routes/smart-home/actions';
import type { Approval } from '@/types/smart-home';

const STATUS: Record<Approval['status'], string> = {
    awaiting_approval: 'Waiting',
    approved: 'Approved',
    declined: 'Declined',
    expired: 'Expired',
    done: 'Approved, done',
    failed: 'Approved, failed',
};

const SOURCE: Record<Approval['source'], string> = {
    dashboard: 'the dashboard',
    api: 'the phone',
    rule: 'an automation',
    assistant: 'the assistant',
};

/** Sensitive actions waiting for the owner, and the last day's answers */
export function ApprovalsCard({
    approvals,
    canApprove,
}: {
    approvals: Approval[];
    canApprove: boolean;
}) {
    if (approvals.length === 0) {
        return null;
    }
    const waiting = approvals.filter((a) => a.status === 'awaiting_approval');
    const opts = { preserveScroll: true };

    return (
        <Card className={waiting.length ? 'border-amber-500' : undefined}>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <ShieldAlert className="size-5" /> Needs approval
                </CardTitle>
                <CardDescription>
                    Unlocking a door or switching on something that heats needs
                    the owner's yes, unless the owner did it.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ul className="space-y-2 text-sm">
                    {approvals.map((a) => (
                        <li
                            key={a.id}
                            className="flex flex-wrap items-center gap-2 rounded-md border p-2"
                            data-testid={`approval-${a.id}`}
                        >
                            <span className="flex-1">
                                <span className="font-medium">{a.label}</span>
                                <span className="text-muted-foreground">
                                    {' '}
                                    · {a.requested_by ?? SOURCE[a.source]}
                                    {a.requested_by &&
                                        ` via ${SOURCE[a.source]}`}{' '}
                                    · {ago(a.created_at)}
                                </span>
                                {a.error && (
                                    <span className="block text-destructive">
                                        {a.error}
                                    </span>
                                )}
                            </span>
                            {a.status === 'awaiting_approval' && canApprove ? (
                                <>
                                    <Button
                                        size="sm"
                                        aria-label={`Approve: ${a.label}`}
                                        onClick={() =>
                                            router.post(
                                                approve.url(a.id),
                                                {},
                                                opts,
                                            )
                                        }
                                    >
                                        <Check /> Approve
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        aria-label={`Decline: ${a.label}`}
                                        onClick={() =>
                                            router.post(
                                                decline.url(a.id),
                                                {},
                                                opts,
                                            )
                                        }
                                    >
                                        <X /> Decline
                                    </Button>
                                </>
                            ) : (
                                <Badge
                                    variant={
                                        a.status === 'awaiting_approval'
                                            ? 'outline'
                                            : a.status === 'done'
                                              ? 'secondary'
                                              : 'destructive'
                                    }
                                >
                                    {a.status === 'awaiting_approval'
                                        ? 'Waiting for the owner'
                                        : STATUS[a.status]}
                                    {a.decided_by && ` (${a.decided_by})`}
                                </Badge>
                            )}
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}
