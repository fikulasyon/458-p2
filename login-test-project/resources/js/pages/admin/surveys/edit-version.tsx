import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
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

type SurveyQuestionOption = {
    id: number;
    value: string;
    label: string;
};

type SurveyQuestion = {
    id: number;
    stable_key: string;
    title: string;
    type: 'multiple_choice' | 'result';
    is_entry: boolean;
    is_result: boolean;
    options: SurveyQuestionOption[];
};

type SurveyEdge = {
    id: number;
    from_question_id: number;
    from_stable_key: string | null;
    from_option_id: number | null;
    from_option_value: string | null;
    to_question_id: number;
    to_stable_key: string | null;
};

type PageProps = {
    errors?: Record<string, string>;
};

function formToObject(form: HTMLFormElement): Record<string, FormDataEntryValue> {
    return Object.fromEntries(new FormData(form).entries());
}

type GraphNode = {
    id: number;
    x: number;
    y: number;
    label: string;
};

type GraphEdge = {
    id: number;
    path: string;
    label: string;
    labelX: number;
    labelY: number;
    labelWidth: number;
};

function buildGraphLayout(questions: SurveyQuestion[], edges: SurveyEdge[]) {
    const nodeWidth = 230;
    const nodeHeight = 72;
    const layerGap = 110;
    const colGap = 40;
    const marginX = 30;
    const marginY = 30;

    const questionMap = new Map(questions.map((question, index) => [question.id, { question, index }]));
    const indegree = new Map<number, number>();
    const outgoing = new Map<number, number[]>();

    questions.forEach((question) => {
        indegree.set(question.id, 0);
        outgoing.set(question.id, []);
    });

    edges.forEach((edge) => {
        if (!questionMap.has(edge.from_question_id) || !questionMap.has(edge.to_question_id)) {
            return;
        }

        outgoing.get(edge.from_question_id)?.push(edge.to_question_id);
        indegree.set(edge.to_question_id, (indegree.get(edge.to_question_id) ?? 0) + 1);
    });

    const queue: number[] = [];
    indegree.forEach((degree, id) => {
        if (degree === 0) {
            queue.push(id);
        }
    });

    const layer = new Map<number, number>();
    questions.forEach((question) => layer.set(question.id, question.is_entry ? 0 : 1));

    let cursor = 0;
    while (cursor < queue.length) {
        const currentId = queue[cursor++];
        const currentLayer = layer.get(currentId) ?? 0;

        (outgoing.get(currentId) ?? []).forEach((toId) => {
            layer.set(toId, Math.max(layer.get(toId) ?? 0, currentLayer + 1));
            const updated = (indegree.get(toId) ?? 0) - 1;
            indegree.set(toId, updated);

            if (updated === 0) {
                queue.push(toId);
            }
        });
    }

    const layers = new Map<number, SurveyQuestion[]>();
    questions
        .slice()
        .sort((a, b) => (questionMap.get(a.id)?.index ?? 0) - (questionMap.get(b.id)?.index ?? 0))
        .forEach((question) => {
            const currentLayer = layer.get(question.id) ?? 0;
            const list = layers.get(currentLayer) ?? [];
            list.push(question);
            layers.set(currentLayer, list);
        });

    const positionedNodes = new Map<number, GraphNode>();
    const allLayerKeys = Array.from(layers.keys()).sort((a, b) => a - b);
    allLayerKeys.forEach((layerKey) => {
        const list = layers.get(layerKey) ?? [];
        list.forEach((question, index) => {
            positionedNodes.set(question.id, {
                id: question.id,
                x: marginX + index * (nodeWidth + colGap),
                y: marginY + layerKey * (nodeHeight + layerGap),
                label: `${question.stable_key}: ${question.title}`,
            });
        });
    });

    let maxX = 0;
    let maxY = 0;
    positionedNodes.forEach((node) => {
        maxX = Math.max(maxX, node.x + nodeWidth + marginX);
        maxY = Math.max(maxY, node.y + nodeHeight + marginY);
    });

    const routedEdges = edges
        .map((edge) => {
            const fromNode = positionedNodes.get(edge.from_question_id);
            const toNode = positionedNodes.get(edge.to_question_id);

            if (!fromNode || !toNode) {
                return null;
            }

            return { edge, fromNode, toNode };
        })
        .filter(
            (
                item,
            ): item is {
                edge: SurveyEdge;
                fromNode: GraphNode;
                toNode: GraphNode;
            } => item !== null,
        );

    const incomingTotals = new Map<number, number>();
    routedEdges.forEach(({ edge }) => {
        incomingTotals.set(
            edge.to_question_id,
            (incomingTotals.get(edge.to_question_id) ?? 0) + 1,
        );
    });

    const incomingSeen = new Map<number, number>();

    const graphEdges: GraphEdge[] = routedEdges
        .map(({ edge, fromNode, toNode }) => {
            const sx = fromNode.x + nodeWidth / 2;
            const sy = fromNode.y + nodeHeight;
            const tx = toNode.x + nodeWidth / 2;
            const ty = toNode.y;
            const c1y = sy + 46;
            const c2y = ty - 46;
            const totalIncoming = incomingTotals.get(edge.to_question_id) ?? 1;
            const seenIndex = incomingSeen.get(edge.to_question_id) ?? 0;
            incomingSeen.set(edge.to_question_id, seenIndex + 1);
            const slotOffset = seenIndex - (totalIncoming - 1) / 2;
            const labelT = 0.78;
            const label = edge.from_option_value ? `if "${edge.from_option_value}"` : 'edge';
            const labelWidth = Math.min(180, Math.max(96, label.length * 7 + 20));

            return {
                id: edge.id,
                path: `M ${sx} ${sy} C ${sx} ${c1y}, ${tx} ${c2y}, ${tx} ${ty}`,
                label,
                labelX: sx + (tx - sx) * labelT + slotOffset * 24,
                labelY: sy + (ty - sy) * labelT - 10 + slotOffset * 10,
                labelWidth,
            };
        })
        .filter((edge): edge is GraphEdge => edge !== null);

    return {
        width: Math.max(maxX, 640),
        height: Math.max(maxY, 360),
        nodeWidth,
        nodeHeight,
        nodes: Array.from(positionedNodes.values()),
        edges: graphEdges,
    };
}

export default function EditSurveyVersion({
    survey,
    version,
    versions,
    questions,
    edges,
}: {
    survey: {
        id: number;
        title: string;
        description: string | null;
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
    questions: SurveyQuestion[];
    edges: SurveyEdge[];
}) {
    const { errors = {} } = usePage<PageProps>().props;

    const questionForm = useForm({
        stable_key: '',
        title: '',
        node_kind: 'question',
    });

    const edgeForm = useForm({
        from_question_id: questions.find((question) => question.options.length > 0)?.id?.toString() ?? '',
        from_option_id:
            questions.find((question) => question.options.length > 0)?.options?.[0]?.id?.toString() ?? '',
        to_question_id: questions[1]?.id?.toString() ?? questions[0]?.id?.toString() ?? '',
    });
    const { data: edgeData, setData: setEdgeData } = edgeForm;

    const questionById = useMemo(
        () => new Map(questions.map((question) => [question.id, question])),
        [questions],
    );

    const sourceQuestions = useMemo(
        () => questions.filter((question) => question.options.length > 0),
        [questions],
    );

    useEffect(() => {
        if (sourceQuestions.length === 0) {
            if (edgeData.from_question_id !== '') {
                setEdgeData('from_question_id', '');
            }

            if (edgeData.from_option_id !== '') {
                setEdgeData('from_option_id', '');
            }

            return;
        }

        const activeSource =
            sourceQuestions.find((question) => question.id === Number(edgeData.from_question_id)) ??
            sourceQuestions[0];

        const normalizedSourceId = activeSource.id.toString();
        if (edgeData.from_question_id !== normalizedSourceId) {
            setEdgeData('from_question_id', normalizedSourceId);
        }

        const selectedOptionExists = activeSource.options.some(
            (option) => option.id === Number(edgeData.from_option_id),
        );
        const normalizedOptionId = selectedOptionExists
            ? edgeData.from_option_id
            : (activeSource.options[0]?.id?.toString() ?? '');

        if (edgeData.from_option_id !== normalizedOptionId) {
            setEdgeData('from_option_id', normalizedOptionId);
        }

        const targetExists = questions.some(
            (question) => question.id === Number(edgeData.to_question_id),
        );
        if (!targetExists) {
            const fallbackTarget =
                questions.find((question) => question.id !== activeSource.id) ?? questions[0];
            setEdgeData('to_question_id', fallbackTarget?.id?.toString() ?? '');
        }
    }, [
        edgeData.from_option_id,
        edgeData.from_question_id,
        edgeData.to_question_id,
        questions,
        setEdgeData,
        sourceQuestions,
    ]);

    const selectedFromQuestion = questionById.get(Number(edgeForm.data.from_question_id));
    const sourceOptions = selectedFromQuestion?.options ?? [];
    const graph = useMemo(() => buildGraphLayout(questions, edges), [questions, edges]);
    const [graphFullscreen, setGraphFullscreen] = useState(false);
    const edgeArrowId = 'edge-arrow-main';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Survey Architect', href: '/admin/surveys' },
        {
            title: `${survey.title} v${version.version_number}`,
            href: `/admin/surveys/${survey.id}/versions/${version.id}`,
        },
    ];

    const draftLocked = version.status !== 'draft';

    const renderGraph = (arrowId: string) => (
        <svg
            width={graph.width}
            height={graph.height}
            viewBox={`0 0 ${graph.width} ${graph.height}`}
            role="img"
            aria-label="Survey graph preview"
        >
            <defs>
                <marker
                    id={arrowId}
                    viewBox="0 0 10 10"
                    refX="9"
                    refY="5"
                    markerWidth="6"
                    markerHeight="6"
                    orient="auto-start-reverse"
                >
                    <path d="M 0 0 L 10 5 L 0 10 z" fill="currentColor" />
                </marker>
            </defs>

            {graph.edges.map((edge) => (
                <g key={edge.id}>
                    <path
                        d={edge.path}
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="1.6"
                        markerEnd={`url(#${arrowId})`}
                        opacity="0.65"
                    />
                    <rect
                        x={edge.labelX - edge.labelWidth / 2}
                        y={edge.labelY - 13}
                        width={edge.labelWidth}
                        height={18}
                        rx={4}
                        fill="hsl(var(--background))"
                        opacity="0.9"
                    />
                    <text
                        x={edge.labelX}
                        y={edge.labelY}
                        textAnchor="middle"
                        fontSize="12"
                        fill="currentColor"
                    >
                        {edge.label}
                    </text>
                </g>
            ))}

            {graph.nodes.map((node) => (
                <g key={node.id}>
                    <rect
                        x={node.x}
                        y={node.y}
                        width={graph.nodeWidth}
                        height={graph.nodeHeight}
                        rx={10}
                        fill="hsl(var(--background))"
                        stroke="currentColor"
                        opacity="0.85"
                    />
                    <text
                        x={node.x + 10}
                        y={node.y + 41}
                        fontSize="13"
                        fill="currentColor"
                    >
                        {node.label.length > 38 ? `${node.label.slice(0, 37)}...` : node.label}
                    </text>
                </g>
            ))}
        </svg>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Survey ${survey.title} v${version.version_number}`} />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3 rounded-lg border p-4">
                    <div>
                        <h1 className="text-xl font-semibold">{survey.title}</h1>
                        <p className="text-sm text-muted-foreground">
                            Version v{version.version_number} ({version.status}
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
                                router.post(
                                    `/admin/surveys/${survey.id}/versions/${version.id}/clone`,
                                )
                            }
                        >
                            Clone To New Draft
                        </Button>
                        <Button
                            onClick={() =>
                                router.post(
                                    `/admin/surveys/${survey.id}/versions/${version.id}/publish`,
                                )
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
                    {errors.publish && (
                        <p className="w-full text-sm text-destructive">{errors.publish}</p>
                    )}
                    {errors.version && (
                        <p className="w-full text-sm text-destructive">{errors.version}</p>
                    )}
                    {errors.version_delete && (
                        <p className="w-full text-sm text-destructive">{errors.version_delete}</p>
                    )}
                </div>

                <div className="rounded-lg border p-4">
                    <h2 className="mb-2 font-medium">Version Timeline</h2>
                    <div className="flex flex-wrap gap-2">
                        {versions.map((item) => (
                            <Button
                                key={item.id}
                                variant={item.id === version.id ? 'default' : 'secondary'}
                                onClick={() =>
                                    router.visit(`/admin/surveys/${survey.id}/versions/${item.id}`)
                                }
                            >
                                v{item.version_number} - {item.status}
                                {item.is_active ? ' (active)' : ''}
                            </Button>
                        ))}
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_560px]">
                    <div className="space-y-6">
                        <div className="rounded-lg border p-4">
                            <h2 className="mb-3 font-medium">Add Node</h2>
                            <form
                                className="grid gap-3 md:grid-cols-3"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    questionForm.post(
                                        `/admin/surveys/${survey.id}/versions/${version.id}/questions`,
                                        {
                                            preserveScroll: true,
                                            onSuccess: () =>
                                                questionForm.setData({
                                                    stable_key: '',
                                                    title: '',
                                                    node_kind: 'question',
                                                }),
                                        },
                                    );
                                }}
                            >
                                <div className="space-y-1">
                                    <Label htmlFor="stable_key">Stable Key</Label>
                                    <Input
                                        id="stable_key"
                                        value={questionForm.data.stable_key}
                                        onChange={(event) =>
                                            questionForm.setData('stable_key', event.target.value)
                                        }
                                        placeholder="q_new"
                                        disabled={draftLocked}
                                    />
                                    <InputError message={questionForm.errors.stable_key} />
                                </div>

                                <div className="space-y-1">
                                    <Label htmlFor="title">Node Title</Label>
                                    <Input
                                        id="title"
                                        value={questionForm.data.title}
                                        onChange={(event) =>
                                            questionForm.setData('title', event.target.value)
                                        }
                                        placeholder="Which track fits you best?"
                                        disabled={draftLocked}
                                    />
                                    <InputError message={questionForm.errors.title} />
                                </div>

                                <div className="space-y-1">
                                    <Label htmlFor="node_kind">Node Type</Label>
                                    <select
                                        id="node_kind"
                                        className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm text-foreground"
                                        value={questionForm.data.node_kind}
                                        onChange={(event) =>
                                            questionForm.setData('node_kind', event.target.value as 'question' | 'result')
                                        }
                                        disabled={draftLocked}
                                    >
                                        <option value="question">Question Node</option>
                                        <option value="result">Result Node</option>
                                    </select>
                                    <InputError message={questionForm.errors.node_kind} />
                                </div>

                                <div className="md:col-span-3">
                                    <Button type="submit" disabled={questionForm.processing || draftLocked}>
                                        Add Node
                                    </Button>
                                </div>
                            </form>
                        </div>

                        <div className="space-y-4 rounded-lg border p-4">
                            <h2 className="font-medium">Nodes (Collapsible)</h2>
                            {questions.map((question) => (
                                <details key={question.id} className="rounded-md border p-3">
                                    <summary className="flex cursor-pointer flex-wrap items-center gap-2 text-sm">
                                        <span className="font-medium">{question.stable_key}</span>
                                        <span className="text-muted-foreground">{question.title}</span>
                                        <span className="rounded border px-1.5 py-0.5 text-xs">
                                            {question.is_result ? 'Result' : 'Question'}
                                        </span>
                                        {question.is_entry && (
                                            <span className="rounded border px-1.5 py-0.5 text-xs">Entry</span>
                                        )}
                                    </summary>

                                    <div className="mt-3 space-y-3">
                                        <form
                                            className="grid gap-2 md:grid-cols-3"
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
                                                <Input
                                                    name="stable_key"
                                                    defaultValue={question.stable_key}
                                                    disabled={draftLocked}
                                                />
                                            </div>
                                            <div className="space-y-1">
                                                <Label>Title</Label>
                                                <Input name="title" defaultValue={question.title} disabled={draftLocked} />
                                            </div>
                                            <div className="space-y-1">
                                                <Label>Type</Label>
                                                <select
                                                    name="node_kind"
                                                    defaultValue={question.is_result ? 'result' : 'question'}
                                                    className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm text-foreground"
                                                    disabled={draftLocked}
                                                >
                                                    <option value="question">Question Node</option>
                                                    <option value="result">Result Node</option>
                                                </select>
                                            </div>
                                            <div className="md:col-span-3 flex items-center gap-2">
                                                <Button type="submit" size="sm" disabled={draftLocked}>
                                                    Save Node
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
                                                    Delete Node
                                                </Button>
                                            </div>
                                        </form>

                                        {question.is_result ? (
                                            <div className="rounded-md border p-3 text-sm text-muted-foreground">
                                                Result nodes are terminal outcomes. They do not have answer options.
                                            </div>
                                        ) : (
                                            <div className="space-y-2 rounded-md border p-3">
                                                <h3 className="text-sm font-medium">Options</h3>

                                                {question.options.map((option) => (
                                                    <form
                                                        key={option.id}
                                                        className="grid gap-2 md:grid-cols-3"
                                                        onSubmit={(event) => {
                                                            event.preventDefault();
                                                            router.patch(
                                                                `/admin/surveys/${survey.id}/versions/${version.id}/questions/${question.id}/options/${option.id}`,
                                                                formToObject(event.currentTarget),
                                                                { preserveScroll: true },
                                                            );
                                                        }}
                                                    >
                                                        <Input name="value" defaultValue={option.value} disabled={draftLocked} />
                                                        <Input name="label" defaultValue={option.label} disabled={draftLocked} />
                                                        <div className="flex gap-2">
                                                            <Button type="submit" size="sm" disabled={draftLocked}>
                                                                Save
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="destructive"
                                                                disabled={draftLocked}
                                                                onClick={() =>
                                                                    router.delete(
                                                                        `/admin/surveys/${survey.id}/versions/${version.id}/questions/${question.id}/options/${option.id}`,
                                                                        { preserveScroll: true },
                                                                    )
                                                                }
                                                            >
                                                                Delete
                                                            </Button>
                                                        </div>
                                                    </form>
                                                ))}

                                                <form
                                                    className="grid gap-2 md:grid-cols-3"
                                                    onSubmit={(event) => {
                                                        event.preventDefault();
                                                        const formElement = event.currentTarget;
                                                        router.post(
                                                            `/admin/surveys/${survey.id}/versions/${version.id}/questions/${question.id}/options`,
                                                            formToObject(formElement),
                                                            {
                                                                preserveScroll: true,
                                                                onSuccess: () => formElement.reset(),
                                                            },
                                                        );
                                                    }}
                                                >
                                                    <Input name="value" placeholder="option value" disabled={draftLocked} />
                                                    <Input name="label" placeholder="option label" disabled={draftLocked} />
                                                    <Button type="submit" disabled={draftLocked}>
                                                        Add Option
                                                    </Button>
                                                </form>
                                            </div>
                                        )}
                                    </div>
                                </details>
                            ))}
                            {errors.option && <p className="text-sm text-destructive">{errors.option}</p>}
                        </div>

                        <div className="space-y-4 rounded-lg border p-4">
                            <h2 className="font-medium">Conditional Edges</h2>
                            <p className="text-sm text-muted-foreground">
                                Rule format: if source question answer equals selected option, move to target node (question or result).
                            </p>

                            <form
                                className="grid gap-2 rounded-md border p-3 md:grid-cols-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    edgeForm.post(`/admin/surveys/${survey.id}/versions/${version.id}/edges`, {
                                        preserveScroll: true,
                                    });
                                }}
                            >
                                <select
                                    className="h-9 rounded-md border border-input bg-background px-3 text-sm text-foreground"
                                    value={edgeForm.data.from_question_id}
                                    onChange={(event) => {
                                        const questionId = event.target.value;
                                        edgeForm.setData('from_question_id', questionId);
                                        const firstOption = questionById.get(Number(questionId))?.options?.[0];
                                        edgeForm.setData('from_option_id', firstOption?.id?.toString() ?? '');
                                    }}
                                    disabled={draftLocked || questions.length < 2 || sourceQuestions.length === 0}
                                >
                                    {sourceQuestions.length === 0 && (
                                        <option value="">No questions with options</option>
                                    )}
                                    {sourceQuestions.map((question) => (
                                        <option key={question.id} value={question.id}>
                                            Source: {question.stable_key} - {question.title}
                                        </option>
                                    ))}
                                </select>

                                <select
                                    className="h-9 rounded-md border border-input bg-background px-3 text-sm text-foreground"
                                    value={edgeForm.data.from_option_id}
                                    onChange={(event) => edgeForm.setData('from_option_id', event.target.value)}
                                    disabled={draftLocked || sourceOptions.length === 0}
                                >
                                    {sourceOptions.length === 0 && <option value="">No options</option>}
                                    {sourceOptions.map((option) => (
                                        <option key={option.id} value={option.id}>
                                            Option: {option.label} ({option.value})
                                        </option>
                                    ))}
                                </select>

                                <select
                                    className="h-9 rounded-md border border-input bg-background px-3 text-sm text-foreground"
                                    value={edgeForm.data.to_question_id}
                                    onChange={(event) =>
                                        edgeForm.setData('to_question_id', event.target.value)
                                    }
                                    disabled={draftLocked || questions.length < 2}
                                >
                                    {questions.map((question) => (
                                        <option key={question.id} value={question.id}>
                                            Target: {question.stable_key} - {question.is_result ? 'Result' : 'Question'}
                                        </option>
                                    ))}
                                </select>

                                <Button
                                    type="submit"
                                    disabled={
                                        draftLocked ||
                                        questions.length < 2 ||
                                        !edgeForm.data.from_option_id ||
                                        edgeForm.processing
                                    }
                                >
                                    Add Edge
                                </Button>
                            </form>

                            {edges.map((edge) => (
                                <div
                                    key={edge.id}
                                    className="flex items-center justify-between rounded-md border p-3 text-sm"
                                >
                                    <div>
                                        <span className="font-medium">{edge.from_stable_key}</span>
                                        {' / '}
                                        <span>{edge.from_option_value}</span>
                                        {' -> '}
                                        <span className="font-medium">{edge.to_stable_key}</span>
                                    </div>
                                    <Button
                                        size="sm"
                                        variant="destructive"
                                        disabled={draftLocked}
                                        onClick={() =>
                                            router.delete(
                                                `/admin/surveys/${survey.id}/versions/${version.id}/edges/${edge.id}`,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Delete
                                    </Button>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="rounded-lg border p-4 lg:sticky lg:top-4 lg:max-h-[calc(100vh-2rem)] lg:overflow-auto">
                        <div className="mb-3 flex items-center justify-between gap-2">
                            <h2 className="font-medium">Graph Preview</h2>
                            <Button
                                type="button"
                                size="sm"
                                variant="secondary"
                                onClick={() => setGraphFullscreen(true)}
                            >
                                Full Screen
                            </Button>
                        </div>
                        <p className="mb-3 text-sm text-muted-foreground">
                            Live DAG view of the current version with edge labels.
                        </p>

                        {questions.length === 0 ? (
                            <p className="text-sm text-muted-foreground">Add nodes to see the graph.</p>
                        ) : (
                            <div className="overflow-auto rounded-md border bg-muted/10">
                                {renderGraph(edgeArrowId)}
                            </div>
                        )}
                    </div>
                </div>

                {graphFullscreen && (
                    <div className="fixed inset-0 z-50 bg-background/95 p-4">
                        <div className="flex h-full flex-col gap-3 rounded-lg border bg-background p-4">
                            <div className="flex items-center justify-between">
                                <h2 className="font-medium">Graph Preview (Full Screen)</h2>
                                <Button type="button" onClick={() => setGraphFullscreen(false)}>
                                    Close
                                </Button>
                            </div>
                            <div className="flex-1 overflow-auto rounded-md border bg-muted/10">
                                {renderGraph(`${edgeArrowId}-fullscreen`)}
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
