import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type SurveyVersionSummary = {
    id: number;
    version_number: number;
    status: string;
    is_active: boolean;
    published_at: string | null;
};

type SurveySummary = {
    id: number;
    title: string;
    description: string | null;
    survey_type: 'multiple_choice' | 'rating' | 'open_ended';
    active_version_id: number | null;
    active_version: SurveyVersionSummary | null;
    latest_draft: { id: number; version_number: number } | null;
    versions: SurveyVersionSummary[];
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Survey Architect',
        href: '/admin/surveys',
    },
];

export default function SurveyArchitectIndex({ surveys }: { surveys: SurveySummary[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Survey Architect" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">Survey Architect</h1>
                        <p className="text-sm text-muted-foreground">
                            Create and manage versioned adaptive surveys for web and mobile.
                        </p>
                    </div>
                    <Button asChild>
                        <Link href="/admin/surveys/create">Create Survey</Link>
                    </Button>
                </div>

                <div className="space-y-4">
                    {surveys.length === 0 && (
                        <div className="rounded-lg border p-4 text-sm text-muted-foreground">
                            No surveys yet. Start by creating your first survey.
                        </div>
                    )}

                    {surveys.map((survey) => (
                        <div key={survey.id} className="space-y-3 rounded-lg border p-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <h2 className="font-medium">{survey.title}</h2>
                                    <p className="text-sm text-muted-foreground">
                                        {survey.description || 'No description'}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Type:{' '}
                                        {survey.survey_type === 'multiple_choice'
                                            ? 'Multiple Choice'
                                            : survey.survey_type === 'rating'
                                              ? 'Rating'
                                              : 'Open-Ended'}
                                    </p>
                                </div>
                                <div className="flex gap-2">
                                    {survey.latest_draft ? (
                                        <Button
                                            variant="secondary"
                                            onClick={() =>
                                                router.visit(
                                                    `/admin/surveys/${survey.id}/versions/${survey.latest_draft?.id}`,
                                                )
                                            }
                                        >
                                            Edit Draft v{survey.latest_draft.version_number}
                                        </Button>
                                    ) : null}

                                    {survey.active_version ? (
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    `/admin/surveys/${survey.id}/versions/${survey.active_version?.id}/clone`,
                                                )
                                            }
                                        >
                                            Clone Active To Draft
                                        </Button>
                                    ) : null}

                                    <Button
                                        variant="destructive"
                                        onClick={() => {
                                            const confirmed = window.confirm(
                                                `Delete survey "${survey.title}"? This removes all versions and related data.`,
                                            );

                                            if (!confirmed) {
                                                return;
                                            }

                                            router.delete(`/admin/surveys/${survey.id}`);
                                        }}
                                    >
                                        Delete Survey
                                    </Button>
                                </div>
                            </div>

                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="text-left text-muted-foreground">
                                            <th className="py-2">Version</th>
                                            <th className="py-2">Status</th>
                                            <th className="py-2">Published</th>
                                            <th className="py-2">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {survey.versions.map((version) => (
                                            <tr key={version.id} className="border-t">
                                                <td className="py-2">v{version.version_number}</td>
                                                <td className="py-2">
                                                    {version.status}
                                                    {version.is_active ? ' (active)' : ''}
                                                </td>
                                                <td className="py-2">
                                                    {version.published_at
                                                        ? new Date(version.published_at).toLocaleString()
                                                        : '-'}
                                                </td>
                                                <td className="py-2">
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            router.visit(
                                                                `/admin/surveys/${survey.id}/versions/${version.id}`,
                                                            )
                                                        }
                                                    >
                                                        Open
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
