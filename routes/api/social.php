<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Social API Routes
|--------------------------------------------------------------------------
|
| Artist follows, comments, shares, and social interactions.
|
*/

// Artist Follow API Routes (auth required)
Route::middleware('auth:sanctum')->prefix('artists')->name('api.artists.')->group(function () {
    Route::post('/{artist:id}/follow', [\App\Http\Controllers\Api\Social\ArtistFollowController::class, 'follow'])->name('follow');
    Route::delete('/{artist:id}/follow', [\App\Http\Controllers\Api\Social\ArtistFollowController::class, 'unfollow'])->name('unfollow');
    Route::get('/{artist:id}/follow/status', [\App\Http\Controllers\Api\Social\ArtistFollowController::class, 'status'])->name('follow.status');
});

// Comments API Routes (polymorphic comments on any entity)
Route::prefix('comments')->name('api.comments.')->group(function () {
    // Public: List comments for any commentable entity
    Route::get('/{commentableType}/{commentableId}', [\App\Http\Controllers\Api\Social\CommentController::class, 'index'])
        ->name('index');

    // Auth required: Create, update, delete, like, reply
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/', [\App\Http\Controllers\Api\Social\CommentController::class, 'store'])->name('store');
        Route::put('/{comment}', [\App\Http\Controllers\Api\Social\CommentController::class, 'update'])->name('update');
        Route::delete('/{comment}', [\App\Http\Controllers\Api\Social\CommentController::class, 'destroy'])->name('destroy');
        Route::post('/{comment}/like', [\App\Http\Controllers\Api\Social\CommentController::class, 'toggleLike'])->name('like');
        Route::post('/{comment}/reply', [\App\Http\Controllers\Api\Social\CommentController::class, 'reply'])->name('reply');
    });
});

// Reviews API Routes (polymorphic reviews on any entity)
Route::prefix('reviews')->name('api.reviews.')->group(function () {
    Route::get('/{reviewableType}/{reviewableId}', [\App\Http\Controllers\Api\Social\ReviewController::class, 'index'])
        ->name('index');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/{reviewableType}/{reviewableId}/eligibility', [\App\Http\Controllers\Api\Social\ReviewController::class, 'eligibility'])->name('eligibility');
        Route::post('/', [\App\Http\Controllers\Api\Social\ReviewController::class, 'store'])->name('store');
        Route::put('/{review}', [\App\Http\Controllers\Api\Social\ReviewController::class, 'update'])->name('update');
        Route::delete('/{review}', [\App\Http\Controllers\Api\Social\ReviewController::class, 'destroy'])->name('destroy');
        Route::post('/{review}/helpful', [\App\Http\Controllers\Api\Social\ReviewController::class, 'markHelpful'])->name('helpful');
    });
});

// Shares API Routes (auth required)
Route::middleware('auth:sanctum')->prefix('shares')->name('api.shares.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Social\ShareController::class, 'index'])->name('index');
    Route::post('/', [\App\Http\Controllers\Api\Social\ShareController::class, 'store'])->name('store');
});

// Share viewing (public - for shared link tracking)
Route::prefix('shares')->name('api.shares.')->group(function () {
    Route::get('/{share}', [\App\Http\Controllers\Api\Social\ShareController::class, 'show'])->name('show');
    Route::get('/{share}/view', [\App\Http\Controllers\Api\Social\ShareController::class, 'view'])->name('view');
});

// Activity Interaction Routes
Route::prefix('activities')->name('api.activities.')->group(function () {
    // Like/Unlike activity (requires auth)
    Route::middleware('auth:sanctum')->post('/{activity}/like', [\App\Http\Controllers\Api\ActivityController::class, 'like'])->name('like');
    Route::middleware('auth:sanctum')->delete('/{activity}/like', [\App\Http\Controllers\Api\ActivityController::class, 'unlike'])->name('unlike');

    // Comments (requires auth for creating)
    Route::get('/{activity}/comments', [\App\Http\Controllers\Api\ActivityController::class, 'getComments'])->name('comments');
    Route::middleware('auth:sanctum')->post('/{activity}/comments', [\App\Http\Controllers\Api\ActivityController::class, 'addComment'])->name('comments.add');
});
