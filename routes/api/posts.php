<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Posts API Routes (Edula Social Posts)
|--------------------------------------------------------------------------
|
| User-generated posts for the Edula community feed.
| Includes CRUD, likes, bookmarks, reposts, and comments.
|
*/

// Public post endpoints
Route::prefix('posts')->name('api.posts.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\PostController::class, 'index'])->name('index');

    // Single post (public)
    Route::get('/{post}', [\App\Http\Controllers\Api\PostController::class, 'show'])->name('show');

    // Post comments (public read)
    Route::get('/{post}/comments', [\App\Http\Controllers\Api\PostController::class, 'comments'])->name('comments');

    // Likers (public read)
    Route::get('/{post}/likers', [\App\Http\Controllers\Api\PostController::class, 'likers'])->name('likers');
});

// Authenticated post endpoints
Route::middleware('auth:sanctum')->prefix('posts')->name('api.posts.')->group(function () {
    Route::post('/', [\App\Http\Controllers\Api\PostController::class, 'store'])->name('store');
    Route::put('/{post}', [\App\Http\Controllers\Api\PostController::class, 'update'])->name('update');
    Route::delete('/{post}', [\App\Http\Controllers\Api\PostController::class, 'destroy'])->name('destroy');

    // Like / Unlike
    Route::post('/{post}/like', [\App\Http\Controllers\Api\PostController::class, 'like'])->name('like');
    Route::delete('/{post}/like', [\App\Http\Controllers\Api\PostController::class, 'unlike'])->name('unlike');

    // Bookmark / Unbookmark
    Route::post('/{post}/bookmark', [\App\Http\Controllers\Api\PostController::class, 'bookmark'])->name('bookmark');
    Route::delete('/{post}/bookmark', [\App\Http\Controllers\Api\PostController::class, 'unbookmark'])->name('unbookmark');

    // Repost
    Route::post('/{post}/repost', [\App\Http\Controllers\Api\PostController::class, 'repost'])->name('repost');

    // Comments (authenticated create/delete)
    Route::post('/{post}/comments', [\App\Http\Controllers\Api\PostController::class, 'storeComment'])->name('comments.store');
    Route::delete('/{post}/comments/{comment}', [\App\Http\Controllers\Api\PostController::class, 'destroyComment'])->name('comments.destroy');
});

// Comment like (separate prefix since it's not under /posts)
Route::middleware('auth:sanctum')->prefix('comments')->name('api.comments.')->group(function () {
    Route::post('/{comment}/like', [\App\Http\Controllers\Api\PostController::class, 'likeComment'])->name('like');
});

// User social endpoints (suggested, follow/unfollow)
Route::middleware('auth:sanctum')->prefix('users')->name('api.users.')->group(function () {
    Route::get('/search', [\App\Http\Controllers\Api\UserSocialController::class, 'search'])->name('search');
    Route::get('/suggested', [\App\Http\Controllers\Api\UserSocialController::class, 'suggested'])->name('suggested');
    Route::post('/{user}/follow', [\App\Http\Controllers\Api\UserSocialController::class, 'follow'])->name('follow');
    Route::delete('/{user}/follow', [\App\Http\Controllers\Api\UserSocialController::class, 'unfollow'])->name('unfollow');
});
