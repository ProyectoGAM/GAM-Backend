<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\CalculateFeedRecipeAction;
use App\Actions\Inventory\ChangeFeedRecipeStatusAction;
use App\Actions\Inventory\CreateFeedRecipeAction;
use App\Actions\Inventory\UpdateFeedRecipeQuantitiesAction;
use App\Enums\Inventory\FeedRecipeStatus;
use App\Http\Requests\Inventory\CalculateFeedRecipeRequest;
use App\Http\Requests\Inventory\ChangeFeedRecipeStatusRequest;
use App\Http\Requests\Inventory\ListFeedRecipesRequest;
use App\Http\Requests\Inventory\StoreFeedRecipeRequest;
use App\Http\Requests\Inventory\UpdateFeedRecipeQuantitiesRequest;
use App\Http\Requests\Inventory\ViewFeedRecipeRequest;
use App\Http\Resources\Inventory\FeedRecipeCalculationResource;
use App\Http\Resources\Inventory\FeedRecipeResource;
use App\Models\Inventory\FeedRecipe;
use App\Queries\Inventory\ListFeedRecipesQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final readonly class FeedRecipeController
{
    public function index(ListFeedRecipesRequest $request, ListFeedRecipesQuery $query): AnonymousResourceCollection
    {
        return FeedRecipeResource::collection($query->execute($request->validated()));
    }

    public function store(StoreFeedRecipeRequest $request, CreateFeedRecipeAction $action): JsonResponse
    {
        $recipe = $action->execute($request->attributesForAction(), $request->actor());

        return (new FeedRecipeResource($recipe))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(ViewFeedRecipeRequest $request, FeedRecipe $recipe): FeedRecipeResource
    {
        return new FeedRecipeResource($recipe->load('ingredients.product'));
    }

    public function update(UpdateFeedRecipeQuantitiesRequest $request, FeedRecipe $recipe, UpdateFeedRecipeQuantitiesAction $action): FeedRecipeResource
    {
        return new FeedRecipeResource($action->execute($recipe, ['ingredients' => $request->ingredientsForAction()], $request->actor()));
    }

    public function status(ChangeFeedRecipeStatusRequest $request, FeedRecipe $recipe, ChangeFeedRecipeStatusAction $action): FeedRecipeResource
    {
        return new FeedRecipeResource($action->execute($recipe, FeedRecipeStatus::from($request->validated('estado')), $request->actor()));
    }

    public function calculate(CalculateFeedRecipeRequest $request, FeedRecipe $recipe, CalculateFeedRecipeAction $action): FeedRecipeCalculationResource
    {
        return new FeedRecipeCalculationResource($action->execute($recipe, $request->validated('cantidad_g'), $request->actor()));
    }
}
