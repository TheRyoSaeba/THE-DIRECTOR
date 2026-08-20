<?php

use App\Http\Controllers\Auth\CharacterController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

// ! Admin routes
Route::middleware(['auth', 'admin', 'throttle:players'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', [
            \App\Http\Controllers\Admin\AdminController::class,
            'index',
        ])->name('index');

        Route::post('/careers/{career}/earns', [
            \App\Http\Controllers\Admin\AdminController::class,
            'createEarn',
        ])->name('careers.earns.create');

        Route::post('/careers/{career}/earns/{earn}', [
            \App\Http\Controllers\Admin\AdminController::class,
            'updateEarn',
        ])->name('careers.earns.update');

        Route::delete('/careers/{career}/earns/{earn}', [
            \App\Http\Controllers\Admin\AdminController::class,
            'deleteEarn',
        ])->name('careers.earns.delete');

        Route::post('/items', [
            \App\Http\Controllers\Admin\AdminController::class,
            'createItem',
        ])->name('items.create');

        Route::post('/items/{item}', [
            \App\Http\Controllers\Admin\AdminController::class,
            'updateItem',
        ])->name('items.update');

        Route::delete('/items/{item}', [
            \App\Http\Controllers\Admin\AdminController::class,
            'deleteItem',
        ])->name('items.delete');

        Route::post('/cities/{city}', [
            \App\Http\Controllers\Admin\AdminController::class,
            'updateCity',
        ])->name('cities.update');

        Route::post('/properties', [
            \App\Http\Controllers\Admin\AdminController::class,
            'createProperty',
        ])->name('properties.create');

        Route::post('/properties/{property}', [
            \App\Http\Controllers\Admin\AdminController::class,
            'updateProperty',
        ])->name('properties.update');

        Route::post('/businesses/{business}', [
            \App\Http\Controllers\Admin\AdminController::class,
            'updateBusiness',
        ])->name('businesses.update');

        Route::post('/businesses/{business}/clear-owner', [
            \App\Http\Controllers\Admin\AdminController::class,
            'clearBusinessOwner',
        ])->name('businesses.clearOwner');

        Route::post('/announcements', [
            \App\Http\Controllers\Admin\AdminController::class,
            'createAnnouncement',
        ])->name('announcements.create');

        Route::post('/announcements/{announcement}', [
            \App\Http\Controllers\Admin\AdminController::class,
            'updateAnnouncement',
        ])->name('announcements.update');

        Route::delete('/announcements/{announcement}', [
            \App\Http\Controllers\Admin\AdminController::class,
            'deleteAnnouncement',
        ])->name('announcements.delete');

        Route::get('/users/{user}/detail', [
            \App\Http\Controllers\Admin\AdminController::class,
            'userDetail',
        ])->name('users.detail');

        Route::post('/users/{user}/ban', [
            \App\Http\Controllers\Admin\AdminController::class,
            'banUser',
        ])->name('users.ban');

        Route::post('/users/{user}/unban', [
            \App\Http\Controllers\Admin\AdminController::class,
            'unbanUser',
        ])->name('users.unban');

        Route::post('/cron/sync', [
            \App\Http\Controllers\Admin\AdminController::class,
            'syncCron',
        ])->name('cron.sync');

        Route::post('/cron/toggle', [
            \App\Http\Controllers\Admin\AdminController::class,
            'toggleCron',
        ])->name('cron.toggle');

        Route::post('/cron/update', [
            \App\Http\Controllers\Admin\AdminController::class,
            'updateCron',
        ])->name('cron.update');

        Route::post('/forum/categories', [
            \App\Http\Controllers\Admin\AdminController::class,
            'createForumCategory',
        ])->name('forum.categories.create');

        Route::post('/forum/categories/{category}', [
            \App\Http\Controllers\Admin\AdminController::class,
            'updateForumCategory',
        ])->name('forum.categories.update');

        Route::delete('/forum/categories/{category}', [
            \App\Http\Controllers\Admin\AdminController::class,
            'deleteForumCategory',
        ])->name('forum.categories.delete');

        Route::post('/forum/posts', [
            \App\Http\Controllers\Admin\AdminController::class,
            'createForumPost',
        ])->name('forum.posts.create');

        Route::delete('/forum/posts/{post}', [
            \App\Http\Controllers\Admin\AdminController::class,
            'deleteForumPost',
        ])->name('forum.posts.delete');

        Route::post('/forum/posts/{post}/pin', [
            \App\Http\Controllers\Admin\AdminController::class,
            'toggleForumPin',
        ])->name('forum.posts.pin');

        Route::post('/forum/posts/{post}/lock', [
            \App\Http\Controllers\Admin\AdminController::class,
            'toggleForumLock',
        ])->name('forum.posts.lock');

        // ── Wiki admin: data endpoints only. Editing UX is inline on the public wiki.
        // {page:id} and {category:id} pin route-model binding to the primary key —
        // the models' getRouteKeyName() returns 'slug' for the public /wiki/{cat}/{page}
        // routes, which would otherwise hijack these admin lookups.
        Route::name('wiki.')->prefix('wiki')->group(function () {
            Route::post('/categories',                 [\App\Http\Controllers\Admin\WikiAdminController::class, 'storeCategory'])->name('categories.store');
            Route::post('/categories/{category:id}',   [\App\Http\Controllers\Admin\WikiAdminController::class, 'updateCategory'])->name('categories.update');
            Route::delete('/categories/{category:id}', [\App\Http\Controllers\Admin\WikiAdminController::class, 'destroyCategory'])->name('categories.destroy');

            Route::post('/pages',             [\App\Http\Controllers\Admin\WikiAdminController::class, 'storePage'])->name('pages.store');
            Route::post('/pages/{page:id}',   [\App\Http\Controllers\Admin\WikiAdminController::class, 'updatePage'])->name('pages.update');
            Route::delete('/pages/{page:id}', [\App\Http\Controllers\Admin\WikiAdminController::class, 'destroyPage'])->name('pages.destroy');
        });
    });


if (app()->isLocal()) {
    Route::get('/dev-login',  [\App\Http\Controllers\Auth\DevLoginController::class, 'create'])->name('dev.login');
    Route::post('/dev-login', [\App\Http\Controllers\Auth\DevLoginController::class, 'store'])->name('dev.login.store');
}

Route::get('/', [LoginController::class, 'create'])
    ->name('login');

Route::middleware(['guest', 'throttle:guest'])->group(function () {
    Route::get('/auth/google', [
        LoginController::class,
        'redirectToGoogle',
    ])->name('auth.google');
    Route::get('/auth/google/callback', [
        LoginController::class,
        'handleGoogleCallback',
    ])->name('auth.google.callback');
});
// ! 2 db queries

Route::get('/admined', [PageController::class, 'admined'])->name('admined');

Route::get('/unsubscribe/{user}', [PageController::class, 'unsubscribe'])
    ->middleware('signed')
    ->name('unsubscribe');

// Public wiki — readable by anyone (no auth). Editing endpoints are admin-only.
// throttle:guest = 60/min by IP, same limiter the OAuth landing routes use.
Route::middleware('throttle:guest')->group(function () {
    Route::get('/wiki',                   [\App\Http\Controllers\WikiController::class, 'index'])->name('wiki.index');
    Route::get('/wiki/{category}/{page}', [\App\Http\Controllers\WikiController::class, 'show'])->name('wiki.show');
});

Route::middleware(['auth', 'throttle:players'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});

// ! All Authenticated Routes
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [PageController::class, 'dashboard'])->name('dashboard');

    Route::get('/announcements', [PageController::class, 'announcements'])->name('announcements');

    //   ? Character creation could probably find a better block
    Route::get('/character/create', [
        CharacterController::class,
        'create',
    ])->name('character.create');

    Route::post('/character/store', [
        CharacterController::class,
        'store',
    ])->name('character.store');

    Route::get('/profile', [ProfileController::class, 'index'])->name(
        'profile',
    );

    Route::get('/conflict', [
        \App\Http\Controllers\ConflictController::class,
        'index',
    ])->name('conflict');

    Route::get('/conflict/results', [
        \App\Http\Controllers\ConflictController::class,
        'results',
    ])->name('conflict.results');

    Route::get('/profile/{displayName}', [
        ProfileController::class,
        'show',
    ])->name('profile.show');

    Route::post('/profile/{displayName}/revive', [
        \App\Http\Controllers\HospitalController::class,
        'revive',
    ])->middleware('throttle:actions')->name('profile.revive');

    Route::put('/profile', [ProfileController::class, 'update'])->name(
        'profile.update',
    );

    Route::get('/work', [
        \App\Http\Controllers\WorkController::class,
        'index',
    ])->name('work');

    Route::post('/work/attempt', [
        \App\Http\Controllers\WorkController::class,
        'attempt',
    ])
        ->middleware('throttle:actions')
        ->name('work.attempt');

    Route::get('/talents', [
        \App\Http\Controllers\TalentController::class,
        'index',
    ])->name('talents');

    Route::post('/talents/activate', [
        \App\Http\Controllers\TalentController::class,
        'activate',
    ])
        ->middleware('throttle:actions')
        ->name('talents.activate');

    Route::get('/help', [PageController::class, 'help'])->name('help');

    Route::get('/forum/data', [
        \App\Http\Controllers\ForumController::class,
        'data',
    ])->name('forum.data');
    Route::get('/forum/post/{post}/detail', [
        \App\Http\Controllers\ForumController::class,
        'postDetail',
    ])->name('forum.post.detail');
    Route::post('/forum', [
        \App\Http\Controllers\ForumController::class,
        'store',
    ])
        ->middleware('throttle:actions')
        ->name('forum.store');
    Route::post('/forum/post/{post}/reply', [
        \App\Http\Controllers\ForumController::class,
        'reply',
    ])
        ->middleware('throttle:actions')
        ->name('forum.reply');
    Route::post('/forum/post/{post}/edit', [
        \App\Http\Controllers\ForumController::class,
        'editPost',
    ])
        ->middleware('throttle:actions')
        ->name('forum.post.edit');
    Route::delete('/forum/post/{post}', [
        \App\Http\Controllers\ForumController::class,
        'deletePost',
    ])
        ->middleware('throttle:actions')
        ->name('forum.post.delete');
    Route::post('/forum/reply/{reply}/edit', [
        \App\Http\Controllers\ForumController::class,
        'editReply',
    ])
        ->middleware('throttle:actions')
        ->name('forum.reply.edit');
    Route::delete('/forum/reply/{reply}', [
        \App\Http\Controllers\ForumController::class,
        'deleteReply',
    ])
        ->middleware('throttle:actions')
        ->name('forum.reply.delete');
    Route::get('/settings', [SettingsController::class, 'index'])->name(
        'settings',
    );

    Route::put('/settings/account', [
        SettingsController::class,
        'updateAccount',
    ])->name('settings.account');

    Route::put('/settings/appearance', [
        SettingsController::class,
        'updateAppearance',
    ])->name('settings.appearance');

    Route::post('/settings/equip', [SettingsController::class, 'equip'])->name(
        'settings.equip',
    );
    Route::post('/settings/unequip', [
        SettingsController::class,
        'unequip',
    ])->name('settings.unequip');
    Route::post('/settings/drop', [SettingsController::class, 'drop'])->name(
        'settings.drop',
    );
    Route::post('/settings/stash', [SettingsController::class, 'stash'])->name(
        'settings.stash',
    );
    Route::post('/settings/unstash', [
        SettingsController::class,
        'unstash',
    ])->name('settings.unstash');
    Route::post('/settings/sell', [SettingsController::class, 'sell'])
        ->middleware('throttle:actions')
        ->name('settings.sell');
    Route::post('/settings/consume', [SettingsController::class, 'consume'])
        ->middleware('throttle:actions')
        ->name('settings.consume');

    Route::post('/settings/detonate', [SettingsController::class, 'detonate'])
        ->middleware('throttle:actions')
        ->name('settings.detonate');
    Route::post('/settings/quit-career', [
        SettingsController::class,
        'quitCareer',
    ])->name('settings.quit-career');
    Route::post('/settings/quit-life', [
        SettingsController::class,
        'quitLife',
    ])->name('settings.quit-life');
    Route::post('/settings/quit-study', [
        SettingsController::class,
        'quitStudy',
    ])->name('settings.quit-study');

    Route::get('/journal', [
        \App\Http\Controllers\JournalController::class,
        'index',
    ])->name('journal');

    Route::get('/messages', [
        \App\Http\Controllers\MessageController::class,
        'index',
    ])->name('messages');
    
    Route::get('/messages/name/{displayName}', [PageController::class, 'openMessageByName'])->name('messages.open-by-name');
    Route::get('/messages/{conversationId}', [
        \App\Http\Controllers\MessageController::class,
        'show',
    ])->name('messages.show');
    Route::post('/messages', [
        \App\Http\Controllers\MessageController::class,
        'store',
    ])->name('messages.store');
    Route::post('/messages/{conversationId}', [
        \App\Http\Controllers\MessageController::class,
        'sendMessage',
    ])->name('messages.send');

    Route::post('/journal/{id}/read', [
        \App\Http\Controllers\JournalController::class,
        'markAsRead',
    ])
        ->middleware('throttle:actions')
        ->name('journal.mark-read');

    Route::post('/journal/read-all', [
        \App\Http\Controllers\JournalController::class,
        'markAllAsRead',
    ])
        ->middleware('throttle:actions')
        ->name('journal.mark-all-read');

    Route::delete('/journal/all', [
        \App\Http\Controllers\JournalController::class,
        'deleteAll',
    ])
        ->middleware('throttle:actions')
        ->name('journal.delete-all');

    Route::post('/journal/{id}/save', [
        \App\Http\Controllers\JournalController::class,
        'saveEntry',
    ])
        ->middleware('throttle:actions')
        ->name('journal.save');

    Route::post('/journal/{id}/forward', [
        \App\Http\Controllers\JournalController::class,
        'forward',
    ])
        ->middleware('throttle:actions')
        ->name('journal.forward');

    Route::delete('/journal/{id}', [
        \App\Http\Controllers\JournalController::class,
        'deleteEntry',
    ])
        ->middleware('throttle:actions')
        ->name('journal.delete');

    Route::post('/journal/{id}/accept', [
        \App\Http\Controllers\JournalController::class,
        'acceptRequest',
    ])
        ->middleware('throttle:actions')
        ->name('journal.accept');

    Route::post('/journal/{id}/decline', [
        \App\Http\Controllers\JournalController::class,
        'declineRequest',
    ])
        ->middleware('throttle:actions')
        ->name('journal.decline');

    Route::post('/conflict/gbh', [
        \App\Http\Controllers\ConflictController::class,
        'gbh',
    ])
        ->middleware('throttle:actions')
        ->name('conflict.gbh');

    Route::post('/conflict/attack', [
        \App\Http\Controllers\ConflictController::class,
        'attack',
    ])
        ->middleware('throttle:actions')
        ->name('conflict.attack');

    
    Route::post('/conflict/organized/initiate', [\App\Http\Controllers\ConflictController::class, 'organizedHitInitiate'])
        ->middleware('throttle:actions')->name('conflict.organized.initiate');
    Route::post('/conflict/organized/accept/{initiatorId}', [\App\Http\Controllers\ConflictController::class, 'organizedHitAccept'])
        ->middleware('throttle:actions')->name('conflict.organized.accept');
    Route::post('/conflict/organized/decline/{initiatorId}', [\App\Http\Controllers\ConflictController::class, 'organizedHitDecline'])
        ->middleware('throttle:actions')->name('conflict.organized.decline');
    Route::post('/conflict/organized/cancel', [\App\Http\Controllers\ConflictController::class, 'organizedHitCancel'])
        ->middleware('throttle:actions')->name('conflict.organized.cancel');
    Route::post('/conflict/organized/execute', [\App\Http\Controllers\ConflictController::class, 'organizedHitExecute'])
        ->middleware('throttle:actions')->name('conflict.organized.execute');

    // ! The special routes
    Route::get('/death', [\App\Http\Controllers\Auth\DeathController::class, 'index'])->name('death');
    Route::post('/death/last-words', [\App\Http\Controllers\Auth\DeathController::class, 'saveLastWords'])->name('death.last-words');
    Route::post('/death/reincarnate', [\App\Http\Controllers\Auth\DeathController::class, 'reincarnate'])->name('death.reincarnate');
    Route::get('/leaderboard', [\App\Http\Controllers\LeaderboardController::class, 'index'])->name('leaderboard');

    Route::get('/banned', [PageController::class, 'banned'])->name('banned');

    Route::get('/hospital', [PageController::class, 'hospital'])->name('hospital');

    Route::get('/jail', [\App\Http\Controllers\JailController::class, 'index'])->name('jail');

    Route::post('/jail/work', [\App\Http\Controllers\JailController::class, 'work'])
        ->middleware('throttle:actions')
        ->name('jail.work');

    // ! actions

    Route::get('/actions', [\App\Http\Controllers\ActionController::class, 'index'])
        ->name('actions.index');

    Route::post('/actions/crypto-rug-pull', [\App\Http\Controllers\ActionController::class, 'cryptoRugPull'])
        ->middleware('throttle:actions')
        ->name('actions.crypto-rug-pull');

    Route::post('/actions/community-service', [\App\Http\Controllers\ActionController::class, 'communityService'])
        ->middleware('throttle:actions')
        ->name('actions.community-service');

    Route::post('/actions/corporation/medical/produce', [\App\Http\Controllers\CorporationProfitController::class, 'produceMedical'])
        ->middleware('throttle:actions')
        ->name('actions.corporation.medical.produce');

    Route::post('/actions/kidnapping', [\App\Http\Controllers\ActionController::class, 'kidnapping'])
        ->middleware('throttle:actions')
        ->name('actions.kidnapping');

    Route::post('/actions/kidnapping/cancel', [\App\Http\Controllers\ActionController::class, 'cancelKidnapping'])
        ->middleware('throttle:actions')
        ->name('actions.kidnapping.cancel');

    Route::post('/actions/launder', [\App\Http\Controllers\ActionController::class, 'launder'])
        ->middleware('throttle:actions')
        ->name('actions.launder');

    Route::post('/actions/plant-bomb', [\App\Http\Controllers\ActionController::class, 'plantBomb'])
        ->middleware('throttle:actions')
        ->name('actions.plant-bomb');

    Route::post('/actions/extortion', [\App\Http\Controllers\ActionController::class, 'extortion'])
        ->middleware('throttle:actions')
        ->name('actions.extortion');

    Route::post('/actions/banker-launder-client', [\App\Http\Controllers\ActionController::class, 'bankerLaunderClient'])
        ->middleware('throttle:actions')
        ->name('actions.banker-launder-client');

    Route::post('/actions/banker-launder-client-refund', [\App\Http\Controllers\ActionController::class, 'bankerLaunderClientRefund'])
        ->middleware('throttle:actions')
        ->name('actions.banker-launder-client-refund');

    // ! Career routes
    Route::group(['prefix' => 'career', 'as' => 'career.'], function () {

        Route::middleware('career:Technician')->group(function () {
            Route::get('/technician', [
                \App\Http\Controllers\CareerController::class,
                'technician',
            ])->name('technician');

            Route::post('/technician/repair', [
                \App\Http\Controllers\CareerController::class,
                'repair',
            ])
                ->middleware('throttle:actions')
                ->name('technician.repair');

            Route::post('/technician/inspect', [
                \App\Http\Controllers\CareerController::class,
                'inspectHome',
            ])
                ->middleware('throttle:actions')
                ->name('technician.inspect');

            Route::post('/technician/repair-home', [
                \App\Http\Controllers\CareerController::class,
                'repairHome',
            ])
                ->middleware('throttle:actions')
                ->name('technician.repair-home');

            Route::post('/technician/construct-corp-property', [
                \App\Http\Controllers\CareerController::class,
                'constructCorporationProperty',
            ])
                ->middleware('throttle:actions')
                ->name('technician.construct-corp-property');
        });

        //todo customs officer Middleware
        Route::middleware('career:Customs')->group(function () {
            Route::get('/customs', [
                \App\Http\Controllers\CareerController::class,
                'customs',
            ])->name('customs');

            Route::post('/customs/search', [
                \App\Http\Controllers\CareerController::class,
                'customsSearch',
            ])->middleware('throttle:actions')->name('customs.search');

            Route::post('/customs/move-requests/approve', [
                \App\Http\Controllers\CareerController::class,
                'customsApproveMove',
            ])->middleware('throttle:actions')->name('customs.move.approve');

            Route::post('/customs/move-requests/deny', [
                \App\Http\Controllers\CareerController::class,
                'customsDenyMove',
            ])->middleware('throttle:actions')->name('customs.move.deny');
        });

        
        Route::middleware('career:Police')->group(function () {
            Route::get('/police', [
                \App\Http\Controllers\PoliceController::class,
                'index',
            ])->name('police');

            Route::post('/police/take/{id}', [
                \App\Http\Controllers\PoliceController::class,
                'takeCase',
            ])->middleware('throttle:actions')->name('police.take');

            Route::post('/police/abandon', [
                \App\Http\Controllers\PoliceController::class,
                'abandonCase',
            ])->middleware('throttle:actions')->name('police.abandon');

            Route::post('/police/investigate/{id}', [
                \App\Http\Controllers\PoliceController::class,
                'investigate',
            ])->middleware('throttle:actions')->name('police.investigate');

            Route::post('/police/refer/{id}', [
                \App\Http\Controllers\PoliceController::class,
                'refer',
            ])->middleware('throttle:actions')->name('police.refer');

            Route::post('/police/dismiss', [
                \App\Http\Controllers\PoliceController::class,
                'dismissOfficer',
            ])->middleware('throttle:actions')->name('police.dismiss');

            Route::post('/police/step-down', [
                \App\Http\Controllers\PoliceController::class,
                'stepDown',
            ])->middleware('throttle:actions')->name('police.step-down');

            Route::post('/police/field-intel/{id}', [
                \App\Http\Controllers\PoliceController::class,
                'fieldIntel',
            ])->middleware('throttle:actions')->name('police.field-intel');

            

            Route::post('/police/actions/arrest', [\App\Http\Controllers\ActionController::class, 'arrest'])
                ->middleware('throttle:actions')
                ->name('police.actions.arrest');

            Route::post('/police/actions/corporate-audit', [\App\Http\Controllers\ActionController::class, 'corporateAudit'])
                ->middleware('throttle:actions')
                ->name('police.actions.corporate-audit');

        });

        Route::middleware('career:Healthcare')->group(function () {
            Route::get('/healthcare', [
                \App\Http\Controllers\HospitalController::class,
                'EmergencyRoom',
            ])->name('healthcare');

            Route::post('/healthcare/surgery', [\App\Http\Controllers\HospitalController::class, 'surgery'])
                ->middleware('throttle:actions')
                ->name('healthcare.surgery');

            Route::post('/healthcare/gender', [\App\Http\Controllers\HospitalController::class, 'genderReassignment'])
                ->middleware('throttle:actions')
                ->name('healthcare.gender');

            Route::post('/healthcare/dismiss', [\App\Http\Controllers\HospitalController::class, 'dismiss'])
                ->middleware('throttle:actions')
                ->name('healthcare.dismiss');

            Route::post('/healthcare/resign', [\App\Http\Controllers\HospitalController::class, 'resign'])
                ->middleware('throttle:actions')
                ->name('healthcare.resign');

            Route::post('/healthcare/ngri', [\App\Http\Controllers\ActionController::class, 'ngri'])
                ->middleware('throttle:actions')
                ->name('healthcare.ngri');
        });

        Route::middleware('career:Corporation')->group(function () {
            Route::get('/corporate', [
                \App\Http\Controllers\CareerController::class,
                'corporate',
            ])->name('corporate');
            Route::post('/corporation/invite', [\App\Http\Controllers\CorporationController::class, 'invite'])
                ->middleware('throttle:actions')
                ->name('corporation.invite');
            Route::post('/corporation/kick', [\App\Http\Controllers\CorporationController::class, 'kick'])
                ->middleware('throttle:actions')
                ->name('corporation.kick');
            Route::post('/corporation/assign-position', [\App\Http\Controllers\CorporationController::class, 'assignPosition'])
                ->middleware('throttle:actions')
                ->name('corporation.assign-position');
            Route::post('/corporation/demote-rank', [\App\Http\Controllers\CorporationController::class, 'demoteRank'])
                ->middleware('throttle:actions')
                ->name('corporation.demote-rank');
            Route::post('/corporation/deposit', [\App\Http\Controllers\CorporationController::class, 'deposit'])
                ->middleware('throttle:actions')
                ->name('corporation.deposit');
            Route::post('/corporation/reserves/deposit', [\App\Http\Controllers\CorporationController::class, 'depositReserves'])
                ->middleware('throttle:actions')
                ->name('corporation.reserves.deposit');
            Route::post('/corporation/distribute', [\App\Http\Controllers\CorporationController::class, 'distribute'])
                ->middleware('throttle:actions')
                ->name('corporation.distribute');
            Route::post('/corporation/reserves/distribute', [\App\Http\Controllers\CorporationController::class, 'distributeReserves'])
                ->middleware('throttle:actions')
                ->name('corporation.reserves.distribute');
            Route::post('/corporation/transfer-ceo', [\App\Http\Controllers\CorporationController::class, 'transferCeo'])
                ->middleware('throttle:actions')
                ->name('corporation.transfer-ceo');
            Route::post('/corporation/board-notes', [\App\Http\Controllers\CorporationController::class, 'updateBoardNotes'])
                ->middleware('throttle:actions')
                ->name('corporation.board-notes');
            Route::post('/corporation/banner', [\App\Http\Controllers\CorporationController::class, 'updateBanner'])
                ->middleware('throttle:actions')
                ->name('corporation.banner');
            Route::post('/corporation/properties/purchase', [\App\Http\Controllers\CorporationController::class, 'purchaseProperty'])
                ->middleware('throttle:actions')
                ->name('corporation.properties.purchase');
            Route::post('/corporation/profits/medical/sell-npc', [\App\Http\Controllers\CorporationProfitController::class, 'sellMedicalToNpc'])
                ->middleware('throttle:actions')
                ->name('corporation.profits.medical.sell-npc');
            Route::post('/corporation/profits/medical/payout-rate', [\App\Http\Controllers\CorporationProfitController::class, 'setMedicalPayoutRate'])
                ->middleware('throttle:actions')
                ->name('corporation.profits.medical.payout-rate');
            Route::post('/corporation/profits/laundering/banker-percentage', [\App\Http\Controllers\CorporationProfitController::class, 'setMirrorBankerPercentage'])
                ->middleware('throttle:actions')
                ->name('corporation.profits.laundering.banker-percentage');
            Route::post('/corporation/profits/laundering/offshore-transfer', [\App\Http\Controllers\CorporationProfitController::class, 'transferToOffshoreTrust'])
                ->middleware('throttle:actions')
                ->name('corporation.profits.laundering.offshore-transfer');
            Route::post('/corporation/profits/laundering/mirror', [\App\Http\Controllers\CorporationProfitController::class, 'createMirrorTransaction'])
                ->middleware('throttle:actions')
                ->name('corporation.profits.laundering.mirror');
            Route::post('/corporation/profits/laundering/mirror/execute', [\App\Http\Controllers\CorporationProfitController::class, 'executeMirrorTransaction'])
                ->middleware('throttle:actions')
                ->name('corporation.profits.laundering.mirror.execute');
            Route::post('/corporation/profits/laundering/mirror/cancel', [\App\Http\Controllers\CorporationProfitController::class, 'cancelMirrorTransaction'])
                ->middleware('throttle:actions')
                ->name('corporation.profits.laundering.mirror.cancel');
            Route::post('/corporation/merger-requests', [\App\Http\Controllers\CorporationController::class, 'proposeMerger'])
                ->middleware('throttle:actions')
                ->name('corporation.merger.propose');
            Route::post('/corporation/merger-requests/{mergerRequest}/accept', [\App\Http\Controllers\CorporationController::class, 'acceptMerger'])
                ->middleware('throttle:actions')
                ->name('corporation.merger.accept');
            Route::post('/corporation/merger-requests/{mergerRequest}/cancel', [\App\Http\Controllers\CorporationController::class, 'cancelMerger'])
                ->middleware('throttle:actions')
                ->name('corporation.merger.cancel');
            Route::post('/corporation/board-promotions', [\App\Http\Controllers\CorporationController::class, 'promoteSubsidiaryCeoToBoard'])
                ->middleware('throttle:actions')
                ->name('corporation.board-promotions.create');
            Route::post('/corporation/subsidiary-invites', [\App\Http\Controllers\CorporationController::class, 'inviteSubsidiary'])
                ->middleware('throttle:actions')
                ->name('corporation.subsidiary-invites.create');
            Route::post('/corporation/subsidiary-invites/{subsidiaryInvite}/accept', [\App\Http\Controllers\CorporationController::class, 'acceptSubsidiaryInvite'])
                ->middleware('throttle:actions')
                ->name('corporation.subsidiary-invites.accept');
            Route::post('/corporation/subsidiary-invites/{subsidiaryInvite}/cancel', [\App\Http\Controllers\CorporationController::class, 'cancelSubsidiaryInvite'])
                ->middleware('throttle:actions')
                ->name('corporation.subsidiary-invites.cancel');
            Route::post('/corporation/subsidiaries/kick', [\App\Http\Controllers\CorporationController::class, 'kickSubsidiary'])
                ->middleware('throttle:actions')
                ->name('corporation.subsidiaries.kick');
            Route::post('/corporation/trust-votes/start', [\App\Http\Controllers\CorporationVotingController::class, 'startTrustVote'])
                ->middleware('throttle:actions')
                ->name('corporation.trust-votes.start');
            Route::post('/corporation/trust-votes/vote', [\App\Http\Controllers\CorporationVotingController::class, 'castTrustVote'])
                ->middleware('throttle:actions')
                ->name('corporation.trust-votes.vote');
            Route::post('/corporation/move/request', [\App\Http\Controllers\CorporationController::class, 'requestMoveHeadquarters'])
                ->middleware('throttle:actions')
                ->name('corporation.move.request');
            Route::post('/corporation/move/cancel', [\App\Http\Controllers\CorporationController::class, 'cancelMoveHeadquarters'])
                ->middleware('throttle:actions')
                ->name('corporation.move.cancel');
            Route::post('/corporation/dissolve', [\App\Http\Controllers\CorporationController::class, 'dissolve'])
                ->middleware('throttle:actions')
                ->name('corporation.dissolve');
            Route::post('/corporation/quit', [\App\Http\Controllers\CorporationController::class, 'quit'])
                ->middleware('throttle:actions')
                ->name('corporation.quit');

            // ! corporate Actions

            Route::get('/corporation/actions/{action?}', fn () => redirect()->route('actions.index'))
                ->name('corporation.actions.redirect');

            Route::post('/corporation/actions/investment-fraud', [\App\Http\Controllers\ActionController::class, 'investmentFraud'])
                ->middleware('throttle:actions')
                ->name('corporation.actions.investment-fraud');
            Route::post('/corporation/actions/investment-fraud/cancel', [\App\Http\Controllers\ActionController::class, 'cancelInvestmentFraud'])
                ->middleware('throttle:actions')
                ->name('corporation.actions.investment-fraud.cancel');
            Route::post('/corporation/actions/medical-sale', [\App\Http\Controllers\ActionController::class, 'medicalSale'])
                ->middleware('throttle:actions')
                ->name('corporation.actions.medical-sale');
            Route::post('/corporation/actions/medical-sale/cancel', [\App\Http\Controllers\ActionController::class, 'cancelMedicineSale'])
                ->middleware('throttle:actions')
                ->name('corporation.actions.medical-sale.cancel');
        });

        Route::middleware('career:Law')->group(function () {
            Route::get('/law', [
                \App\Http\Controllers\LawController::class,
                'index',
            ])->name('law');

            Route::post('/law/prosecute/{id}', [
                \App\Http\Controllers\LawCaseController::class,
                'prosecute',
            ])->middleware('throttle:actions')->name('law.prosecute');

            Route::post('/law/defend/{id}', [
                \App\Http\Controllers\LawCaseController::class,
                'defend',
            ])->middleware('throttle:actions')->name('law.defend');

            Route::post('/law/defense-offer/{offer}/execute', [
                \App\Http\Controllers\LawCaseController::class,
                'executeDefense',
            ])->middleware('throttle:actions')->name('law.defense.execute');

            Route::post('/law/judge-verdict/{id}', [
                \App\Http\Controllers\LawCaseController::class,
                'judgeVerdict',
            ])->middleware('throttle:actions')->name('law.judge-verdict');

            Route::post('/law/judge-sentence/{id}', [
                \App\Http\Controllers\LawCaseController::class,
                'judgeSentence',
            ])->middleware('throttle:actions')->name('law.judge-sentence');

            Route::post('/law/resolve-appeal/{id}', [
                \App\Http\Controllers\LawCaseController::class,
                'resolveAppeal',
            ])->middleware('throttle:actions')->name('law.resolve-appeal');

            Route::post('/law/dismiss', [
                \App\Http\Controllers\LawController::class,
                'dismissMember',
            ])->middleware('throttle:actions')->name('law.dismiss');

            Route::post('/law/step-down', [
                \App\Http\Controllers\LawController::class,
                'stepDown',
            ])->middleware('throttle:actions')->name('law.step-down');
        });

        Route::middleware('career:Banking')->group(function () {
            Route::get('/banking', [
                \App\Http\Controllers\BankController::class,
                'terminal',
            ])->name('banking');

            Route::get('/banking/tickers', [
                \App\Http\Controllers\BankController::class,
                'tickerSnapshots',
            ])->middleware('throttle:players')->name('banking.tickers');

            Route::post('/banking/trade', [
                \App\Http\Controllers\BankController::class,
                'executeTrade',
            ])->middleware('throttle:actions')->name('banking.trade');

            Route::post('/banking/dismiss', [
                \App\Http\Controllers\BankController::class,
                'dismissBanker',
            ])->middleware('throttle:actions')->name('banking.dismiss');

            Route::post('/banking/position-cap', [
                \App\Http\Controllers\BankController::class,
                'updatePositionCap',
            ])->middleware('throttle:actions')->name('banking.position-cap');

            Route::post('/banking/launder/add-client', [
                \App\Http\Controllers\BankController::class, 'launderAddClient',
            ])->middleware('throttle:actions')->name('banking.launder.add-client');

            Route::post('/banking/launder/{offer}/update-cut', [
                \App\Http\Controllers\BankController::class, 'launderUpdateCut',
            ])->middleware('throttle:actions')->name('banking.launder.update-cut');

            Route::post('/banking/launder/{offer}/execute', [
                \App\Http\Controllers\BankController::class, 'launderExecute',
            ])->middleware('throttle:actions')->name('banking.launder.execute');

            
            
        });

        Route::middleware('career:Politics')->group(function () {
            Route::get('/politics', [
                \App\Http\Controllers\CareerController::class,
                'politics',
            ])->name('politics');

            
            
            
            Route::post('/politics/budget', [\App\Http\Controllers\PoliticsController::class, 'budget'])
                ->middleware('throttle:actions')->name('politics.budget');
            Route::post('/politics/taxes', [\App\Http\Controllers\PoliticsController::class, 'taxes'])
                ->middleware('throttle:actions')->name('politics.taxes');
            Route::post('/politics/enable-corp-reg', [\App\Http\Controllers\PoliticsController::class, 'enableCorpReg'])
                ->middleware('throttle:actions')->name('politics.enable-corp-reg');
            Route::post('/politics/audit', [\App\Http\Controllers\PoliticsController::class, 'audit'])
                ->middleware('throttle:actions')->name('politics.audit');
            Route::post('/politics/bond', [\App\Http\Controllers\PoliticsController::class, 'bond'])
                ->middleware('throttle:actions')->name('politics.bond');
            Route::post('/politics/suppress', [\App\Http\Controllers\PoliticsController::class, 'suppress'])
                ->middleware('throttle:actions')->name('politics.suppress');
            Route::post('/politics/pardon', [\App\Http\Controllers\PoliticsController::class, 'pardon'])
                ->middleware('throttle:actions')->name('politics.pardon');
            Route::post('/politics/dismiss-officer', [\App\Http\Controllers\PoliticsController::class, 'dismissOfficer'])
                ->middleware('throttle:actions')->name('politics.dismiss-officer');
            Route::post('/politics/dismiss-commissioner', [\App\Http\Controllers\PoliticsController::class, 'dismissCommissioner'])
                ->middleware('throttle:actions')->name('politics.dismiss-commissioner');
            Route::post('/politics/death-sentence', [\App\Http\Controllers\PoliticsController::class, 'deathSentence'])
                ->middleware('throttle:actions')->name('politics.death-sentence');
            Route::post('/politics/resign', [\App\Http\Controllers\PoliticsController::class, 'resign'])
                ->middleware('throttle:actions')->name('politics.resign');
        });
    });

    
    
    Route::match(['get', 'post'], '/career/promote', [
        \App\Http\Controllers\CareerController::class,
        'promote',
    ])->middleware('throttle:actions')->name('career.promote');

    // ! THESE ARE CAREER SPECIFIC ROUTES THAT NEED AS AN EXCEPTION TO BE ACCESSIBLE FROM ANYWHERE

    
    Route::post('/career/banking/launder/{offer}/cancel', [
        \App\Http\Controllers\BankController::class, 'launderCancel',
    ])->middleware('throttle:actions')->name('career.banking.launder.cancel');

    Route::post('/law/appeal/{id}', [
        \App\Http\Controllers\LawCaseController::class,
        'appeal',
    ])->middleware('throttle:actions')->name('law.appeal');

    // ! Corporation entry routes (player is NOT yet in Corporation career)
    Route::post('/corporation/found', [\App\Http\Controllers\CorporationController::class, 'found'])
        ->middleware('throttle:actions')
        ->name('corporation.found');

    // ! ALL city specific routes defined last.
    Route::middleware('city.access')->group(function () {
        Route::get('/{city}', [CityController::class, 'show'])
            ->middleware('throttle:players')
            ->name('city.show');

        Route::name('city.bank.')
            ->prefix('/{city}/bank')
            ->group(function () {
                Route::get('/', [BankController::class, 'index'])->name('index');
                Route::post('/deposit', [BankController::class, 'deposit'])
                    ->middleware('throttle:actions')
                    ->name('deposit');
                Route::post('/withdraw', [BankController::class, 'withdraw'])
                    ->middleware('throttle:actions')
                    ->name('withdraw');
                Route::post('/transfer', [BankController::class, 'transfer'])
                    ->middleware('throttle:actions')
                    ->name('transfer');
                Route::post('/settings', [BankController::class, 'updateSettings'])
                    ->name('settings');
                Route::post('/certificates/purchase', [BankController::class, 'purchaseCd'])
                    ->middleware('throttle:actions')
                    ->name('certificates.purchase');
                Route::get('/ledger/residents', [BankController::class, 'ledgerResidents'])
                    ->name('ledger.residents');
                Route::get('/ledger/character', [BankController::class, 'ledgerCharacter'])
                    ->name('ledger.character');
            });

            
            Route::name('city.city-hall.')
                ->prefix('/{city}/cityhall')
                ->group(function () {
                    Route::get('/', [\App\Http\Controllers\CityHallController::class, 'index'])->name('index');
                });

        Route::name('city.property.')
            ->prefix('/{city}/property')
            ->group(function () {
                Route::get('/', [
                    \App\Http\Controllers\PropertyController::class,
                    'index',
                ])->name('index');
                Route::post('/purchase', [
                    \App\Http\Controllers\PropertyController::class,
                    'purchase',
                ])
                    ->middleware('throttle:actions')
                    ->name('purchase');
                Route::post('/sell', [
                    \App\Http\Controllers\PropertyController::class,
                    'sell',
                ])
                    ->middleware('throttle:actions')
                    ->name('sell');
            });

        Route::name('city.business.')
            ->prefix('/{city}/business')
            ->group(function () {
                Route::get('/', [
                    \App\Http\Controllers\BusinessController::class,
                    'index',
                ])->name('index');
                Route::post('/venture/purchase', [
                    \App\Http\Controllers\BusinessController::class,
                    'purchase',
                ])
                    ->middleware('throttle:actions')
                    ->name('venture.purchase');
                Route::post('/venture/sell', [
                    \App\Http\Controllers\BusinessController::class,
                    'sell',
                ])
                    ->middleware('throttle:actions')
                    ->name('venture.sell');
                Route::post('/venture/cancel', [
                    \App\Http\Controllers\BusinessController::class,
                    'cancelSale',
                ])
                    ->middleware('throttle:actions')
                    ->name('venture.cancel');
                Route::post('/venture/withdraw', [
                    \App\Http\Controllers\BusinessController::class,
                    'withdraw',
                ])
                    ->middleware('throttle:actions')
                    ->name('withdraw');
                Route::post('/venture/update-description', [
                    \App\Http\Controllers\BusinessController::class,
                    'updateDescription',
                ])
                    ->middleware('throttle:actions')
                    ->name('venture.update-description');
            });

        Route::name('city.pachinko.')
            ->prefix('/{city}/pachinko')
            ->group(function () {
                Route::get('/', [
                    \App\Http\Controllers\PachinkoController::class,
                    'index',
                ])->name('index');
                Route::post('/spin', [
                    \App\Http\Controllers\PachinkoController::class,
                    'spin',
                ])
                    ->middleware('throttle:actions')
                    ->name('spin');
                Route::post('/blackjack/deal', [
                    \App\Http\Controllers\PachinkoController::class,
                    'blackjackDeal',
                ])
                    ->middleware('throttle:actions')
                    ->name('blackjack.deal');
                Route::post('/blackjack/hit', [
                    \App\Http\Controllers\PachinkoController::class,
                    'blackjackHit',
                ])
                    ->middleware('throttle:actions')
                    ->name('blackjack.hit');
                Route::post('/blackjack/stand', [
                    \App\Http\Controllers\PachinkoController::class,
                    'blackjackStand',
                ])
                    ->middleware('throttle:actions')
                    ->name('blackjack.stand');
                Route::post('/blackjack/double', [
                    \App\Http\Controllers\PachinkoController::class,
                    'blackjackDouble',
                ])
                    ->middleware('throttle:actions')
                    ->name('blackjack.double');
                Route::post('/settings', [
                    \App\Http\Controllers\PachinkoController::class,
                    'updateSettings',
                ])->name('settings');
            });

        Route::name('city.shop.')
            ->prefix('/{city}/shop/{business_slug}')
            ->group(function () {
                Route::get('/', [
                    \App\Http\Controllers\ShopController::class,
                    'index',
                ])->name('index');
                Route::post('/purchase', [
                    \App\Http\Controllers\ShopController::class,
                    'purchase',
                ])
                    ->middleware('throttle:actions')
                    ->name('purchase');
                Route::post('/restock', [
                    \App\Http\Controllers\ShopController::class,
                    'restock',
                ])
                    ->middleware('throttle:actions')
                    ->name('restock');
                Route::post('/settings', [
                    \App\Http\Controllers\ShopController::class,
                    'settings',
                ])->name('settings');
                Route::post('/insurance', [
                    \App\Http\Controllers\ShopController::class,
                    'purchaseInsurance',
                ])
                    ->middleware('throttle:actions')
                    ->name('insurance');
            });

        Route::name('city.cityhall.')
            ->prefix('/{city}/city-hall')
            ->group(function () {
                Route::get('/', [\App\Http\Controllers\CityHallController::class, 'index'])->name('index');

                
                Route::post('/announce', [\App\Http\Controllers\CityHallController::class, 'announce'])
                    ->middleware('throttle:actions')->name('announce');
                Route::delete('/announce/{id}', [\App\Http\Controllers\CityHallController::class, 'deleteAnnouncement'])
                    ->middleware('throttle:actions')->name('announce.delete');

                
                Route::post('/forum', [\App\Http\Controllers\CityHallController::class, 'forumStore'])
                    ->middleware('throttle:actions')->name('forum.store');
                Route::post('/forum/{post}/reply', [\App\Http\Controllers\CityHallController::class, 'forumReply'])
                    ->middleware('throttle:actions')->name('forum.reply');
                Route::delete('/forum/{post}', [\App\Http\Controllers\CityHallController::class, 'forumDelete'])
                    ->middleware('throttle:actions')->name('forum.delete');
                Route::post('/forum/{post}/view', [\App\Http\Controllers\CityHallController::class, 'forumView'])
                    ->middleware('throttle:actions')->name('forum.view');
                Route::post('/forum/{post}/pin', [\App\Http\Controllers\CityHallController::class, 'forumPin'])
                    ->middleware('throttle:actions')->name('forum.pin');
                Route::post('/forum/{post}/lock', [\App\Http\Controllers\CityHallController::class, 'forumLock'])
                    ->middleware('throttle:actions')->name('forum.lock');
                Route::post('/settings', [\App\Http\Controllers\CityHallController::class, 'updateSettings'])
                    ->middleware('throttle:actions')->name('settings');

                
                Route::post('/aides', [\App\Http\Controllers\CityHallController::class, 'appointAide'])
                    ->middleware('throttle:actions')->name('aide.appoint');
                Route::delete('/aides/{aide}', [\App\Http\Controllers\CityHallController::class, 'revokeAide'])
                    ->middleware('throttle:actions')->name('aide.revoke');

                
                Route::post('/relocate/{character}/approve', [\App\Http\Controllers\CityHallController::class, 'approveRelocation'])
                    ->middleware('throttle:actions')->name('relocate.approve');
                Route::post('/relocate/{character}/deny', [\App\Http\Controllers\CityHallController::class, 'denyRelocation'])
                    ->middleware('throttle:actions')->name('relocate.deny');

                Route::post('/relocate', [\App\Http\Controllers\CityHallController::class, 'relocate'])
                    ->middleware('throttle:actions')->name('relocate');
            });

        Route::name('city.election.')
            ->prefix('/{city}/election')
            ->group(function () {
                Route::get('/', [\App\Http\Controllers\ElectionController::class, 'index'])->name('index');
                Route::post('/apply', [\App\Http\Controllers\ElectionController::class, 'apply'])
                    ->middleware('throttle:actions')
                    ->name('apply');
                Route::post('/vote', [\App\Http\Controllers\ElectionController::class, 'vote'])
                    ->middleware('throttle:actions')
                    ->name('vote');
                Route::post('/funds', [\App\Http\Controllers\ElectionController::class, 'addFunds'])
                    ->middleware('throttle:actions')
                    ->name('funds');
            });

        Route::name('city.hospital.')
            ->prefix('/{city}/hospital')
            ->group(function () {
                Route::get('/', [\App\Http\Controllers\HospitalController::class, 'index'])->name('index');
                Route::post('/settings', [\App\Http\Controllers\HospitalController::class, 'updateSettings'])
                    ->middleware('throttle:actions')
                    ->name('settings');
                Route::post('/apply-surgery', [\App\Http\Controllers\HospitalController::class, 'applySurgery'])
                    ->middleware('throttle:actions')
                    ->name('apply-surgery');
                Route::post('/apply-gender', [\App\Http\Controllers\HospitalController::class, 'applyGender'])
                    ->middleware('throttle:actions')
                    ->name('apply-gender');
                Route::post('/cancel-surgery', [\App\Http\Controllers\HospitalController::class, 'cancelSurgery'])
                    ->middleware('throttle:actions')
                    ->name('cancel-surgery');
                Route::post('/cancel-gender', [\App\Http\Controllers\HospitalController::class, 'cancelGender'])
                    ->middleware('throttle:actions')
                    ->name('cancel-gender');
            });

        Route::name('city.police.')
            ->prefix('/{city}/police-hq')
            ->group(function () {
                Route::get('/', [\App\Http\Controllers\PoliceHqController::class, 'index'])->name('index');
                Route::post('/enroll', [\App\Http\Controllers\PoliceHqController::class, 'enroll'])
                    ->middleware('throttle:actions')
                    ->name('enroll');
                Route::post('/train', [\App\Http\Controllers\PoliceHqController::class, 'train'])
                    ->middleware('throttle:actions')
                    ->name('train');

                Route::get('/graduate', [\App\Http\Controllers\PoliceHqController::class, 'index']);
                Route::post('/graduate', [\App\Http\Controllers\PoliceHqController::class, 'graduate'])
                    ->middleware('throttle:actions')
                    ->name('graduate');
                Route::post('/turn-in', [\App\Http\Controllers\PoliceHqController::class, 'turnIn'])
                    ->middleware('throttle:actions')
                    ->name('turn-in');
                Route::post('/settings', [\App\Http\Controllers\PoliceHqController::class, 'updateSettings'])
                    ->name('settings');
            });

        Route::name('city.transit-hub.')
            ->prefix('/{city}/transit-hub')
            ->group(function () {
                Route::get('/', [
                    \App\Http\Controllers\TransitHubController::class,
                    'index',
                ])->name('index');
                Route::post('/travel/{destination}', [
                    \App\Http\Controllers\TransitHubController::class,
                    'travel',
                ])
                    ->middleware('throttle:actions')
                    ->name('travel');
                Route::post('/academy/enroll', [
                    \App\Http\Controllers\TransitHubController::class,
                    'enrollAcademy',
                ])
                    ->middleware('throttle:actions')
                    ->name('academy.enroll');
                Route::post('/academy/train', [
                    \App\Http\Controllers\TransitHubController::class,
                    'trainAcademy',
                ])
                    ->middleware('throttle:actions')
                    ->name('academy.train');
                Route::post('/academy/graduate', [
                    \App\Http\Controllers\TransitHubController::class,
                    'graduateAcademy',
                ])
                    ->middleware('throttle:actions')
                    ->name('academy.graduate');
                Route::post('/settings', [
                    \App\Http\Controllers\TransitHubController::class,
                    'updateSettings',
                ])
                    ->middleware('throttle:actions')
                    ->name('settings');
            });

        Route::name('city.university.')
            ->prefix('/{city}/university')
            ->group(function () {
                Route::get('/', [
                    \App\Http\Controllers\UniversityController::class,
                    'index',
                ])->name('index');
                Route::post('/enroll', [
                    \App\Http\Controllers\UniversityController::class,
                    'enroll',
                ])
                    ->middleware('throttle:actions')
                    ->name('enroll');
                Route::post('/study', [
                    \App\Http\Controllers\UniversityController::class,
                    'study',
                ])
                    ->middleware('throttle:actions')
                    ->name('study');
                Route::post('/start-career', [
                    \App\Http\Controllers\UniversityController::class,
                    'startCareer',
                ])
                    ->middleware('throttle:actions')
                    ->name('start-career');
                Route::post('/settings', [
                    \App\Http\Controllers\UniversityController::class,
                    'updateSettings',
                ])->name('settings');
            });

    });

   

        // ! THE END.
    });
