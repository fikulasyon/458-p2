import { Head, Link, useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Survey Architect',
        href: '/admin/surveys',
    },
    {
        title: 'Create Survey',
        href: '/admin/surveys/create',
    },
];

export default function CreateSurvey() {
    const form = useForm({
        title: '',
        description: '',
        survey_type: '',
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create Survey" />

            <div className="mx-auto w-full max-w-2xl space-y-4 p-4">
                <h1 className="text-xl font-semibold">Create Survey</h1>
                <p className="text-sm text-muted-foreground">
                    Choose survey type first, then create metadata and draft version v1.
                </p>

                <form
                    className="space-y-4 rounded-lg border p-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/admin/surveys');
                    }}
                >
                    <div className="space-y-2">
                        <Label>Survey Type</Label>
                        <div className="grid gap-2 md:grid-cols-3">
                            {[
                                {
                                    value: 'multiple_choice',
                                    title: 'Multiple Choice',
                                    description: 'Conditional logic with options and edges.',
                                },
                                {
                                    value: 'rating',
                                    title: 'Rating',
                                    description: 'Linear ordered questions with rating answers.',
                                },
                                {
                                    value: 'open_ended',
                                    title: 'Open-Ended',
                                    description: 'Linear ordered questions with text answers.',
                                },
                            ].map((type) => {
                                const selected = form.data.survey_type === type.value;

                                return (
                                    <button
                                        key={type.value}
                                        type="button"
                                        className={`rounded-md border p-3 text-left transition ${
                                            selected
                                                ? 'border-primary bg-primary/10'
                                                : 'border-border hover:border-primary/50'
                                        }`}
                                        onClick={() => form.setData('survey_type', type.value)}
                                    >
                                        <div className="text-sm font-medium">{type.title}</div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            {type.description}
                                        </div>
                                    </button>
                                );
                            })}
                        </div>
                        <InputError message={form.errors.survey_type} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="title">Title</Label>
                        <Input
                            id="title"
                            value={form.data.title}
                            onChange={(event) => form.setData('title', event.target.value)}
                            placeholder="Dream Job Finder"
                        />
                        <InputError message={form.errors.title} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="description">Description</Label>
                        <textarea
                            id="description"
                            className="min-h-[120px] w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs"
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.target.value)}
                            placeholder="Adaptive survey for conditional career pathing."
                        />
                        <InputError message={form.errors.description} />
                    </div>

                    <div className="flex gap-2">
                        <Button
                            type="submit"
                            disabled={form.processing || form.data.survey_type === ''}
                        >
                            Create Draft
                        </Button>
                        <Button asChild variant="secondary">
                            <Link href="/admin/surveys">Cancel</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
