import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type SurveyVersionSummary = {
    id: number;
    version_number: number;
    status: string;
    is_active: boolean;
    published_at: string | null;
};

type LinearQuestion = {
    id: number;
    stable_key: string;
    title: string;
    type: string;
    position: number;
};

type RatingScale = {
    count: number;
    labels: string[];
};

type PageProps = {
    errors?: Record<string, string>;
};

function formToObject(form: HTMLFormElement): Record<string, FormDataEntryValue> {
    return Object.fromEntries(new FormData(form).entries());
}

export default function EditLinearSurveyVersion({
    survey,
    version,
    versions,
    questions,
    rating_scale,
}: {
    survey: {
        id: number;
        title: string;
        description: string | null;
        survey_type: 'rating' | 'open_ended';
        active_version_id: number | null;
    };
    version: {
        id: number;
        version_number: number;
        status: string;
        is_active: boolean;
        base_version_id: number | null;
        published_at: string | null;
    };
    versions: SurveyVersionSummary[];
    questions: LinearQuestion[];
    rating_scale: RatingScale | null;
}) {
    const { errors = {} } = usePage<PageProps>().props;
    const [draggingQuestionId, setDraggingQuestionId] = useState<number | null>(null);

    const questionForm = useForm({
        stable_key: '',
        title: '',
    });

    const ratingScaleForm = useForm({
        count: rating_scale?.count ?? 5,
        labels:
            rating_scale?.labels ??
            ['Very Bad', 'Bad', 'Neutral', 'Good', 'Excellent'],
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Survey Architect', href: '/admin/surveys' },
        {
            title: `${survey.title} v${version.version_number}`,
            href: `/admin/surveys/${survey.id}/versions/${version.id}`,
        },
    ];

    const draftLocked = version.status !== 'draft';
    const surveyTypeLabel = survey.survey_type === 'rating' ? 'Rating Survey' : 'Open-Ended Survey';

    function normalizeRatingLabels(targetCount: number): string[] {
        const labels = [...ratingScaleForm.data.labels];
        while (labels.length < targetCount) {
            labels.push((labels.length + 1).toString());
        }
        return labels.slice(0, targetCount);
    }

    function handleDrop(targetQuestionId: number) {
        if (draggingQuestionId === null || draggingQuestionId === targetQuestionId || draftLocked) {
            return;
        }

        const orderedIds = questions.map((question) => question.id);
        const sourceIndex = orderedIds.indexOf(draggingQuestionId);
        const targetIndex = orderedIds.indexOf(targetQuestionId);

        if (sourceIndex === -1 || targetIndex === -1) {
            setDraggingQuestionId(null);
            return;
        }

        const nextOrder = [...orderedIds];
        nextOrder.splice(sourceIndex, 1);
        nextOrder.splice(targetIndex, 0, draggingQuestionId);

        setDraggingQuestionId(null);
        router.patch(
            `/admin/surveys/${survey.id}/versions/${version.id}/question-order`,
            { question_ids: nextOrder },
            { preserveScroll: true },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${survey.title} v${version.version_number}`} />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3 rounded-lg border p-4">
                    <div>
                        <h1 className="text-xl font-semibold">{survey.title}</h1>
                        <p className="text-sm text-muted-foreground">
                            {surveyTypeLabel} / Version v{version.version_number} ({version.status}
                            {version.is_active ? ', active' : ''})
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {survey.description || 'No survey description'}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="secondary">
                            <Link href="/admin/surveys">Back</Link>
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(`/admin/surveys/${survey.id}/versions/${version.id}/clone`)
                            }
                        >
                            Clone To New Draft
                        </Button>
                        <Button
                            onClick={() =>
                                router.post(`/admin/surveys/${survey.id}/versions/${version.id}/publish`)
                            }
                            disabled={draftLocked}
                            data-test="publish-version-button"
                        >
                            Publish Version
                        </Button>
                        {!version.is_active && (
                            <Button
                                variant="destructive"
                                onClick={() => {
                                    const confirmed = window.confirm(
                                        `Delete non-active version v${version.version_number}?`,
                                    );

                                    if (!confirmed) {
                                        return;
                                    }

                                    router.delete(`/admin/surveys/${survey.id}/versions/${version.id}`);
                                }}
                            >
                                Delete Draft
                            </Button>
                        )}
                    </div>
                    {errors.publish && <p className="w-full text-sm text-destructive">{errors.publish}</p>}
                    {errors.version && <p className="w-full text-sm text-destructive">{errors.version}</p>}
                    {errors.survey && <p className="w-full text-sm text-destructive">{errors.survey}</p>}
                    {errors.version_delete && <p className="w-full text-sm text-destructive">{errors.version_delete}</p>}
                </div>

                <div className="rounded-lg border p-4">
                    <h2 className="mb-2 font-medium">Version Timeline</h2>
                    <div className="flex flex-wrap gap-2">
                        {versions.map((item) => (
                            <Button
                                key={item.id}
                                variant={item.id === version.id ? 'default' : 'secondary'}
                                onClick={() => router.visit(`/admin/surveys/${survey.id}/versions/${item.id}`)}
                            >
                                v{item.version_number} - {item.status}
                                {item.is_active ? ' (active)' : ''}
                            </Button>
                        ))}
                    </div>
                </div>

                {survey.survey_type === 'rating' && (
                    <div className="rounded-lg border p-4">
                        <h2 className="mb-2 font-medium">Rating Scale</h2>
                        <p className="mb-3 text-sm text-muted-foreground">
                            Configure how many rating options are shown and what each label says.
                        </p>

                        <form
                            className="space-y-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                router.patch(
                                    `/admin/surveys/${survey.id}/versions/${version.id}/rating-scale`,
                                    ratingScaleForm.data,
                                    { preserveScroll: true },
                                );
                            }}
                        >
                            <div className="space-y-1">
                                <Label htmlFor="rating_count">Option Count</Label>
                                <select
                                    id="rating_count"
                                    className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm text-foreground md:w-40"
                                    value={ratingScaleForm.data.count}
                                    onChange={(event) => {
                                        const nextCount = Number(event.target.value);
                                        ratingScaleForm.setData('count', nextCount);
                                        ratingScaleForm.setData('labels', normalizeRatingLabels(nextCount));
                                    }}
                                    disabled={draftLocked}
                                >
                                    {Array.from({ length: 9 }).map((_, index) => {
                                        const value = index + 2;
                                        return (
                                            <option key={value} value={value}>
                                                {value}
                                            </option>
                                        );
                                    })}
                                </select>
                                <InputError message={ratingScaleForm.errors.count} />
                            </div>

                            <div className="grid gap-2 md:grid-cols-2">
                                {ratingScaleForm.data.labels.map((label, index) => (
                                    <div key={index} className="space-y-1">
                                        <Label htmlFor={`rating_label_${index}`}>Label {index + 1}</Label>
                                        <Input
                                            id={`rating_label_${index}`}
                                            value={label}
                                            onChange={(event) => {
                                                const nextLabels = [...ratingScaleForm.data.labels];
                                                nextLabels[index] = event.target.value;
                                                ratingScaleForm.setData('labels', nextLabels);
                                            }}
                                            disabled={draftLocked}
                                        />
                                    </div>
                                ))}
                            </div>
                            <InputError message={ratingScaleForm.errors.labels} />

                            <Button type="submit" disabled={draftLocked || ratingScaleForm.processing}>
                                Save Rating Scale
                            </Button>
                        </form>
                    </div>
                )}

                <div className="rounded-lg border p-4">
                    <h2 className="mb-3 font-medium">Add Question</h2>
                    <p className="mb-3 text-sm text-muted-foreground">
                        These surveys are linear. Question order defines the flow.
                    </p>
                    <form
                        className="grid gap-3 md:grid-cols-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            questionForm.post(`/admin/surveys/${survey.id}/versions/${version.id}/questions`, {
                                preserveScroll: true,
                                onSuccess: () => questionForm.reset(),
                            });
                        }}
                    >
                        <div className="space-y-1">
                            <Label htmlFor="stable_key">Stable Key</Label>
                            <Input
                                id="stable_key"
                                value={questionForm.data.stable_key}
                                onChange={(event) => questionForm.setData('stable_key', event.target.value)}
                                placeholder="q_new"
                                disabled={draftLocked}
                            />
                            <InputError message={questionForm.errors.stable_key} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="title">Question Title</Label>
                            <Input
                                id="title"
                                value={questionForm.data.title}
                                onChange={(event) => questionForm.setData('title', event.target.value)}
                                placeholder={
                                    survey.survey_type === 'rating'
                                        ? 'How satisfied are you with teamwork?'
                                        : 'Tell us what motivates you at work.'
                                }
                                disabled={draftLocked}
                            />
                            <InputError message={questionForm.errors.title} />
                        </div>

                        <div className="md:col-span-2">
                            <Button type="submit" disabled={questionForm.processing || draftLocked}>
                                Add Question
                            </Button>
                        </div>
                    </form>
                </div>

                <div className="space-y-4 rounded-lg border p-4">
                    <h2 className="font-medium">Questions In Order</h2>
                    <p className="text-sm text-muted-foreground">
                        Drag a question card and drop it on another card to reorder.
                    </p>

                    {questions.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            Add your first question to start the linear flow.
                        </p>
                    )}

                    {questions.map((question) => (
                        <details
                            key={question.id}
                            className={`rounded-md border p-3 ${draggingQuestionId === question.id ? 'opacity-60' : ''}`}
                            draggable={!draftLocked}
                            onDragStart={() => setDraggingQuestionId(question.id)}
                            onDragEnd={() => setDraggingQuestionId(null)}
                            onDragOver={(event) => event.preventDefault()}
                            onDrop={() => handleDrop(question.id)}
                        >
                            <summary className="flex cursor-pointer flex-wrap items-center gap-2 text-sm">
                                <span className="rounded border px-1.5 py-0.5 text-xs">#{question.position}</span>
                                <span className="rounded border px-1.5 py-0.5 text-xs">Drag</span>
                                <span className="font-medium">{question.stable_key}</span>
                                <span className="text-muted-foreground">{question.title}</span>
                            </summary>

                            <div className="mt-3 space-y-3">
                                <form
                                    className="grid gap-2 md:grid-cols-2"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        router.patch(
                                            `/admin/surveys/${survey.id}/versions/${version.id}/questions/${question.id}`,
                                            formToObject(event.currentTarget),
                                            { preserveScroll: true },
                                        );
                                    }}
                                >
                                    <div className="space-y-1">
                                        <Label>Stable Key</Label>
                                        <Input name="stable_key" defaultValue={question.stable_key} disabled={draftLocked} />
                                    </div>

                                    <div className="space-y-1">
                                        <Label>Title</Label>
                                        <Input name="title" defaultValue={question.title} disabled={draftLocked} />
                                    </div>

                                    <div className="md:col-span-2 flex flex-wrap items-center gap-2">
                                        <Button type="submit" size="sm" disabled={draftLocked}>
                                            Save Question
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="destructive"
                                            disabled={draftLocked}
                                            onClick={() =>
                                                router.delete(
                                                    `/admin/surveys/${survey.id}/versions/${version.id}/questions/${question.id}`,
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Delete Question
                                        </Button>
                                    </div>
                                </form>
                            </div>
                        </details>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
