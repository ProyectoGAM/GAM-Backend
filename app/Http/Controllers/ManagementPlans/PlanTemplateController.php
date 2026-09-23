<?php

namespace App\Http\Controllers\ManagementPlans;

use App\Actions\ManagementPlans\SavePlanTemplateAction;
use App\Http\Requests\ManagementPlans\ListPlanTemplatesRequest;
use App\Http\Requests\ManagementPlans\PublishPlanTemplateRequest;
use App\Http\Requests\ManagementPlans\StorePlanTemplateRequest;
use App\Http\Requests\ManagementPlans\UpdatePlanTemplateRequest;
use App\Http\Requests\ManagementPlans\ViewPlanTemplateRequest;
use App\Http\Resources\ManagementPlans\PlanTemplateResource;
use App\Models\ManagementPlans\PlanTemplate;
use App\Models\ManagementPlans\PlanTemplateVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class PlanTemplateController
{
    public function index(ListPlanTemplatesRequest $request, SavePlanTemplateAction $action): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = PlanTemplate::query();
        if (! $request->user()?->can('management-plans.manage')) {
            $query->whereNotNull('published_version');
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return PlanTemplateResource::collection($query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50, ['*'], 'page', $filters['page'] ?? 1)
            ->through(fn (PlanTemplate $template): array => $action->snapshot($template, PlanTemplateVersion::query()
                ->where('plan_template_id', $template->id)->where('number', $template->published_version ?? $template->current_version)->with('activities')->firstOrFail())));
    }

    public function store(StorePlanTemplateRequest $request, SavePlanTemplateAction $action): JsonResponse
    {
        $operation = $action->create($request->attributesForAction(), $request->actor());

        return (new PlanTemplateResource([...$operation->result['template'], 'operation_id' => $operation->operation_id]))->response()->setStatusCode(201);
    }

    public function show(ViewPlanTemplateRequest $request, PlanTemplate $planTemplate, SavePlanTemplateAction $action): PlanTemplateResource
    {
        $versionNumber = (int) ($request->validated()['version'] ?? $planTemplate->published_version ?? $planTemplate->current_version);
        $version = PlanTemplateVersion::query()->where('plan_template_id', $planTemplate->id)
            ->where('number', $versionNumber)->with('activities')->firstOrFail();

        return new PlanTemplateResource($action->snapshot($planTemplate, $version));
    }

    public function update(UpdatePlanTemplateRequest $request, PlanTemplate $planTemplate, SavePlanTemplateAction $action): PlanTemplateResource
    {
        $operation = $action->revise($planTemplate, $request->attributesForAction(), $request->actor());

        return new PlanTemplateResource([...$operation->result['template'], 'operation_id' => $operation->operation_id]);
    }

    public function publish(PublishPlanTemplateRequest $request, PlanTemplate $planTemplate, SavePlanTemplateAction $action): PlanTemplateResource
    {
        $operation = $action->publish($planTemplate, $request->attributesForAction(), $request->actor());

        return new PlanTemplateResource([...$operation->result['template'], 'operation_id' => $operation->operation_id]);
    }

    public function retire(PublishPlanTemplateRequest $request, PlanTemplate $planTemplate, SavePlanTemplateAction $action): PlanTemplateResource
    {
        $operation = $action->retire($planTemplate, $request->attributesForAction(), $request->actor());

        return new PlanTemplateResource([...$operation->result['template'], 'operation_id' => $operation->operation_id]);
    }
}
