<?php

use App\Http\Controllers\Team\TeamController;
use App\Http\Middleware\CheckTemplateTypeAndPlan;
use App\Models\Setting;
use App\Http\Controllers\AdvertisController;
use App\Http\Controllers\AIArticleWizardController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Dashboard\AdminController;
use App\Http\Controllers\Market\MarketPlaceController;
use App\Http\Controllers\Dashboard\UserController;
use App\Http\Controllers\AIController;
use App\Http\Controllers\Dashboard\iyzipayActions;
use App\Http\Controllers\Dashboard\SupportController;
use App\Http\Controllers\Dashboard\SettingsController;
use App\Http\Controllers\Dashboard\SearchController;
use App\Http\Controllers\Dashboard\TranslateController;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use App\Http\Controllers\AIChatController;
use App\Http\Controllers\AIFineTuneController;
use App\Http\Controllers\Finance\GatewayController;
use Illuminate\Support\Facades\App;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\Commands\RunHealthChecksCommand;
use Carbon\Carbon;
use App\Http\Controllers\PageController;
use App\Http\Controllers\EmailTemplatesController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\TTSController;
use App\Http\Controllers\AdsController;
use App\Http\Controllers\ChatPdfController;
use App\Http\Controllers\ExportChatController;
use App\Http\Controllers\PromptController;
use App\Http\Controllers\ChatBotController;
use App\Http\Controllers\TestExtensionController;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\File;

use App\Models\SettingTwo;
use App\Http\Controllers\Finance\PaymentProcessController;
use App\Http\Controllers\Finance\MobilePaymentsController;

Route::group(['prefix' => LaravelLocalization::setLocale(), 'middleware' => ['localeSessionRedirect', 'localizationRedirect', 'localeViewPath']], function () {

    Route::prefix('dashboard')->middleware('auth')->name('dashboard.')->group(function () {

        Route::get('/', [UserController::class, 'redirect'])->name('index');

        //User Area
        Route::prefix('user')->name('user.')->group(function () {
            Route::get('/', [UserController::class, 'index'])->name('index');

            # teams
            Route::group([
                'as' => 'team.',
                'prefix' => 'team',
                'controller' => TeamController::class
            ], function () {
                Route::get('', 'index')->name('index');
                Route::get('{team}/invitations', 'invitations')->name('invitations');
                Route::post('{team}/invitation', 'storeInvitation')->name('invitation.store');
                Route::get('{team}/member/{teamMember}/edit', 'teamMember')->name('member.edit');
                Route::post('{team}/member/{teamMember}/update', 'teamMemberUpdate')->name('member.update');
                Route::get('{team}/member/{teamMember}/delete', 'teamMemberDelete')->name('member.delete');
            });

            //Openai generator
            Route::prefix('openai')->name('openai.')
                ->group(function () {
                Route::get('/', [UserController::class, 'openAIList'])->name('list')->middleware('hasTokens');
                Route::get('/favorite-openai', [UserController::class, 'openAIFavoritesList'])->name('list.favorites');
                Route::post('/favorite', [UserController::class, 'openAIFavorite']);
                //Generators
                    Route::middleware([
                        'hasTokens',
                        CheckTemplateTypeAndPlan::class
                    ])
                        ->group(function () {
                            Route::get('/generator/{slug}', [UserController::class, 'openAIGenerator'])->name('generator');
                            Route::get('/generator/{slug}/workbook', [UserController::class, 'openAIGeneratorWorkbook'])->name('generator.workbook');
                        });

                //Generators Generate
                Route::post('/generate', [AIController::class, 'buildOutput']);
                Route::get('/generate', [AIController::class, 'streamedTextOutput']);

                Route::get('/rewrite', [AIController::class, 'reWrite'])->name('rewriter');

                Route::get('/generate/lazyload', [AIController::class, 'lazyLoadImage'])->name('lazyloadimage');

                Route::post('/image/generate', [AIController::class, 'chatImageOutput'])->name('chat.image');

                Route::get('/stream', [AIController::class, 'stream'])->name('stream');

                //Fine Tune
                Route::post('/add-fine-tune', [AIFineTuneController::class, 'addFineTune']);
                Route::post('/delete-fine-tune', [AIFineTuneController::class, 'deleteFineTune']);

                //Low systems
                Route::post('/low/generate_save', [AIController::class, 'lowGenerateSave']);

                Route::post('/generate-speech', [TTSController::class, 'generateSpeech']);

                Route::post('/update-writing', [AIController::class, 'updateWriting']);

                //Documents
                Route::prefix('documents')->name('documents.')->group(function () {
                    Route::get('/all/{id?}', [UserController::class, 'documentsAll'])->name('all');
                    Route::get('/images', [UserController::class, 'documentsImages'])->name('images');
                    Route::get('/single/{slug}', [UserController::class, 'documentsSingle'])->name('single');
                    Route::get('/delete/{slug}', [UserController::class, 'documentsDelete'])->name('delete');
                    Route::get('/delete/image/{slug}', [UserController::class, 'documentsImageDelete'])->name('image.delete');
                    Route::post('/workbook-save', [UserController::class, 'openAIGeneratorWorkbookSave']);

                    Route::post('/update-folder/{folder}', [UserController::class, 'updateFolder'])->name('update-folder');
                    Route::post('/update-file/{file}', [UserController::class, 'updateFile'])->name('update-file');

                    Route::post('/delete-folder/{folder}', [UserController::class, 'deleteFolder'])->name('delete-folder');
                    Route::post('/new-folder', [UserController::class, 'newFolder'])->name('new-folder');
                    Route::post('/move-to-folder', [UserController::class, 'moveToFolder'])->name('move-to-folder');
                });


                Route::middleware('hasTokens')->group(function () {
                    Route::prefix('chat')->name('chat.')->group(function () {
                        Route::get('/ai-chat-list', [AIChatController::class, 'openAIChatList'])->name('list');
                        Route::get('/ai-chat/{slug}', [AIChatController::class, 'openAIChat'])->name('chat');
                        Route::get('/stream', [AIController::class, 'chatStream'])->name('stream');
                        Route::match(['get', 'post'], '/chat-send', [AIChatController::class, 'chatOutput']);
                        Route::match(['get', 'post'], '/chatbot-send', [AIChatController::class, 'chatbotOutput']);
                        Route::post('/open-chat-area-container', [AIChatController::class, 'openChatAreaContainer']);
                        Route::post('/open-chatbot-area', [AIChatController::class, 'openChatBotArea']);
                        Route::post('/start-new-chat', [AIChatController::class, 'startNewChat']);
                        Route::post('/start-new-chatbot', [AIChatController::class, 'startNewChatBot']);
                        Route::post('/search', [AIChatController::class, 'search']);
                        Route::post('/delete-chat', [AIChatController::class, 'deleteChat']);
                        Route::post('/rename-chat', [AIChatController::class, 'renameChat']);

                        Route::post('/transaudio', [AIChatController::class, 'transAudio']);

                        Route::post('/prompts', [PromptController::class, 'getAll']);
                        Route::post('/add-prompt', [PromptController::class, 'addNew']);
                        Route::post('/update-prompt', [PromptController::class, 'updateFav']);

                        // routes/web.php

                        Route::get('/generate-pdf', [ExportChatController::class, 'generatePdf']);
                        Route::get('/generate-word', [ExportChatController::class, 'generateWord']);
                        Route::get('/generate-txt', [ExportChatController::class, 'generateTxt']);

                        //Low systems
                        Route::post('/low/chat_save', [AIChatController::class, 'lowChatSave']);
                    });
                });

                Route::middleware('hasTokens')->group(function () {
                    Route::prefix('articlewizard')->name('articlewizard.')->group(function () {
                        Route::get('/new', [AIArticleWizardController::class, 'newArticle'])->name('new');
                        Route::get('/genarticle', [AIArticleWizardController::class, 'generateArticle'])->name('genarticle');
                        Route::post('/update', [AIArticleWizardController::class, 'updateArticle'])->name('update');
                        Route::post('/clear', [AIArticleWizardController::class, 'clearArticle'])->name('clear');
                        Route::post('/genkeywords', [AIArticleWizardController::class, 'generateKeywords'])->name('genkeywords');
                        Route::post('/gentitles', [AIArticleWizardController::class, 'generateTitles'])->name('gentitles');
                        Route::post('/genoutlines', [AIArticleWizardController::class, 'generateOutlines'])->name('genoutlines');
                        Route::post('/genimages', [AIArticleWizardController::class, 'generateImages'])->name('genimages');
                        Route::post('/remains', [AIArticleWizardController::class, 'userRemaining'])->name('remains');
                        Route::get('/{uid}', [AIArticleWizardController::class, 'editArticle'])->name('edit');
                        Route::resource('/', AIArticleWizardController::class);
                    });
                });

                // Route::middleware('hasTokens')->group(function () {
                //     Route::prefix('vision')->name('vision.')->group(function () {
                //         Route::get('/ai-vision', [AIVisionController::class, 'openAIVision'])->name('vision');
                //     });
                // });

            });
            // user profile settings
            Route::prefix('settings')->name('settings.')->group(function () {
                Route::get('/', [UserController::class, 'userSettings'])->name('index');
                Route::post('/save', [UserController::class, 'userSettingsSave']);
            });
            // Subscription and payment
            Route::prefix('payment')->name('payment.')->group(function () {
                Route::get('/', [UserController::class, 'subscriptionPlans'])->name('subscription');
                Route::get('/prepaid/{planId}/{gatewayCode}', [PaymentProcessController::class, 'startPrepaidPaymentProcess'])->name('startPrepaidPaymentProcess');
                Route::get('/subscribe/{planId}/{gatewayCode}', [PaymentProcessController::class, 'startSubscriptionProcess'])->name('startSubscriptionProcess');
                Route::match(['get', 'post'], '/start/subscription/checkout/{gateway?}/{referral?}', [PaymentProcessController::class, 'startSubscriptionCheckoutProcess'])->name('subscription.checkout');
                Route::match(['get', 'post'], '/start/prepaid/checkout/{gateway?}/{referral?}', [PaymentProcessController::class, 'startPrepaidCheckoutProcess'])->name('prepaid.checkout');
                Route::get('/subscribe-cancel', [PaymentProcessController::class, 'cancelActiveSubscription'])->name('cancelActiveSubscription');
                Route::post('/paypal/create-paypal-order', [PaymentProcessController::class, 'createPayPalOrder'])->name('prepaid.createPayPalOrder');
                Route::post('iyzico/prepaid/callback', [PaymentProcessController::class, 'iyzicoPrepaidCallback'])->name('iyzico.prepaid.callback');
                Route::post('iyzico/subscribe/callback', [PaymentProcessController::class, 'iyzicoSubscribeCallback'])->name('iyzico.subscribe.callback');
                Route::get('iyzico/products', [PaymentProcessController::class, 'iyzicoProductsList'])->name('iyzico.products');  

                Route::get('succesful', [PaymentProcessController::class, 'succesful'])->name('succesful');   
                Route::post('/user-subscribe-cancel/{id}', [PaymentProcessController::class, 'cancelActiveSubscriptionByAdmin'])->name('cancelActiveSubscriptionByAdmin');
                Route::post('/assign-plan', [PaymentProcessController::class, 'assignPlanByAdmin'])->name('assignPlanByAdmin');
                Route::post('/assign-token-plan', [PaymentProcessController::class, 'assignTokenByAdmin'])->name('assignTokenByAdmin');
            });

            //Orders invoice billing
            Route::prefix('orders')->name('orders.')->group(function () {
                Route::get('/', [UserController::class, 'invoiceList'])->name('index');
                Route::get('/order/{order_id}', [UserController::class, 'invoiceSingle'])->name('invoice');
            });
            //Affiliates
            Route::prefix('affiliates')->name('affiliates.')->group(function () {
                Route::get('/', [UserController::class, 'affiliatesList'])->name('index');
                Route::post('/send-invitation', [UserController::class, 'affiliatesListSendInvitation']);
                Route::post('/send-request', [UserController::class, 'affiliatesListSendRequest']);
            });
        });
        //Admin Area
        Route::prefix('admin')->middleware('admin')->name('admin.')->group(function () {
            Route::get('/', [AdminController::class, 'index'])->name('index');

            //Marketplace
            // Route::prefix('marketplace')->name('marketplace.')->group(function () {
            //     Route::get('/', [MarketPlaceController::class, 'index'])->name('index');
            //     Route::get('/extension/{slug}', [MarketPlaceController::class, 'extension'])->name('extension');
            //     Route::get('/buy/{slug}', [MarketPlaceController::class, 'buyExtension'])->name('buyextesion');
            //     Route::post('/buy/{slug}', [MarketPlaceController::class, 'buy'])->name('buy');
            //     Route::get('/licensed-extensions', [MarketPlaceController::class, 'licensedExtension'])->name('liextension');
            // });

            //User Management
            Route::prefix('users')->name('users.')->group(function () {
                Route::get('/', [AdminController::class, 'users'])->name('index');
                Route::get('/add', [AdminController::class, 'usersAdd'])->name('create');
                Route::post('/store', [AdminController::class, 'usersStore'])->name('store');
                Route::get('edit/{user}', [AdminController::class, 'usersEdit'])->name('edit');
                Route::get('/delete/{id}', [AdminController::class, 'usersDelete'])->name('delete');
                Route::post('save', [AdminController::class, 'usersSave'])->name('update');

                Route::get('/finance/{id}', [AdminController::class, 'usersFinance'])->name('finance');
            });


            //Adsense
            Route::prefix('adsense')->name('ads.')->group(function () {
                Route::get('/', [AdsController::class, 'index'])->name('index');
                Route::get('/{id}/edit', [AdsController::class, 'edit'])->name('edit');
                Route::put('/{id}', [AdsController::class, 'update'])->name('update');
                // Route::post('/', [AdsController::class, 'store'])->name('store');
                // Route::delete('/{ad}', [AdsController::class, 'destroy'])->name('destroy');
            });

            //Bank Transactions
            Route::prefix('bank')->name('bank.')->group(function () {
                Route::get('/transactions', [PaymentProcessController::class, 'banktransactions'])->name('transactions.list');
                Route::get('/delete/{id?}', [PaymentProcessController::class, 'bankDelete'])->name('transactions.delete');
                Route::post('/save', [PaymentProcessController::class, 'bankUpdateSave'])->name('transactions.update');
            });

            //Openai management
            Route::prefix('openai')->name('openai.')->group(function () {
                Route::get('/', [AdminController::class, 'openAIList'])->name('list');
                Route::post('/update-status', [AdminController::class, 'openAIListUpdateStatus']);
                Route::post('/update-package-status', [AdminController::class, 'openAIListUpdatePackageStatus']);

                Route::prefix('custom')->name('custom.')->group(function () {
                    Route::get('/', [AdminController::class, 'openAICustomList'])->name('list');
                    Route::get('/add-or-update/{id?}', [AdminController::class, 'openAICustomAddOrUpdate'])->name('addOrUpdate');
                    Route::get('/delete/{id?}', [AdminController::class, 'openAICustomDelete'])->name('delete');
                    Route::post('/save', [AdminController::class, 'openAICustomAddOrUpdateSave']);
                });

                Route::prefix('categories')->name('categories.')->group(function () {
                    Route::get('/', [AdminController::class, 'openAICategoriesList'])->name('list');
                    Route::get('/add-or-update/{id?}', [AdminController::class, 'openAICategoriesAddOrUpdate'])->name('addOrUpdate');
                    Route::get('/delete/{id?}', [AdminController::class, 'openAICategoriesDelete'])->name('delete');
                    Route::post('/save', [AdminController::class, 'openAICategoriesAddOrUpdateSave']);
                });

                Route::prefix('chat')->name('chat.')->group(function () {
                    Route::get('/', [AdminController::class, 'openAIChatList'])->name('list');
                    Route::get('/add-or-update/{id?}', [AdminController::class, 'openAIChatAddOrUpdate'])->name('addOrUpdate');
                    Route::get('/delete/{id?}', [AdminController::class, 'openAIChatDelete'])->name('delete');
                    Route::post('/save', [AdminController::class, 'openAIChatAddOrUpdateSave']);

                    Route::post('/update-plan', [AdminController::class, 'updatePlan'])->name('updatePlan');

                    Route::post('/update-fav', [AdminController::class, 'updateChatFav'])->name('updateChatFav');

                    Route::get('/category', [AdminController::class, 'categoryList'])->name('category');
                    Route::get('/category/add-or-update/{id?}', [AdminController::class, 'addOrUpdateCategory'])->name('addOrUpdateCategory');
                    Route::post('/category/save', [AdminController::class, 'chatCategoriesAddOrUpdateSave']);
                    Route::get('/category/delete/{id?}', [AdminController::class, 'chatCategoriesDelete'])->name('deleteCategory');
                });
            });

            //Finance
            Route::prefix('finance')->name('finance.')->group(function () {
                //Plans
                Route::prefix('plans')->name('plans.')->group(function () {
                    Route::get('/', [AdminController::class, 'paymentPlans'])->name('index');
                    Route::get('/subscription/create-or-update/{id?}', [AdminController::class, 'paymentPlansSubscriptionNewOrEdit'])->name('SubscriptionNewOrEdit');
                    Route::get('/pre-paid/create-or-update/{id?}', [AdminController::class, 'paymentPlansPrepaidNewOrEdit'])->name('PlanNewOrEdit');
                    Route::get('/delete/{id}', [AdminController::class, 'paymentPlansDelete'])->name('delete');
                    Route::post('/save', [AdminController::class, 'paymentPlansSave']);
                });

                //Payment Gateways
                Route::prefix('paymentGateways')->name('paymentGateways.')->group(function () {
                    Route::get('/', [GatewayController::class, 'paymentGateways'])->name('index');
                    Route::get('/settings/{code}', [GatewayController::class, 'gatewaySettings'])->name('settings');
                    Route::post('/settings/save', [GatewayController::class, 'gatewaySettingsSave'])->name('settings.save');
                    Route::post('/settings/tax/save', [GatewayController::class, 'gatewaySettingsTaxSave'])->name('settings.tax.save');
                });

                //Mobile
                Route::prefix('mobile')->name('mobile.')->group(function () {
                    Route::match(['get', 'post'], '/', [MobilePaymentsController::class, 'mobilePlanIdSettings'])->name('index');
                });
            });

            //Testimonials
            Route::prefix('testimonials')->name('testimonials.')->group(function () {
                Route::get('/', [AdminController::class, 'testimonials'])->name('index');
                Route::get('/create-or-update/{id?}', [AdminController::class, 'testimonialsNewOrEdit'])->name('TestimonialsNewOrEdit');
                Route::get('/delete/{id}', [AdminController::class, 'testimonialsDelete'])->name('delete');
                Route::post('/save', [AdminController::class, 'testimonialsSave']);
            });

            //Clients
            Route::prefix('clients')->name('clients.')->group(function () {
                Route::get('/', [AdminController::class, 'clients'])->name('index');
                Route::get('/create-or-update/{id?}', [AdminController::class, 'clientsNewOrEdit'])->name('ClientsNewOrEdit');
                Route::get('/delete/{id}', [AdminController::class, 'clientsDelete'])->name('delete');
                Route::post('/save', [AdminController::class, 'clientsSave']);
            });

            //How it Works
            Route::prefix('howitWorks')->name('howitWorks.')->group(function () {
                Route::get('/', [AdminController::class, 'howitWorks'])->name('index');
                Route::get('/create-or-update/{id?}', [AdminController::class, 'howitWorksNewOrEdit'])->name('HowitWorksNewOrEdit');
                Route::get('/delete/{id}', [AdminController::class, 'howitWorksDelete'])->name('delete');
                Route::post('/save', [AdminController::class, 'howitWorksSave']);
                Route::post('/bottom-line', [AdminController::class, 'howitWorksBottomLineSave']);
            });

            //Settings
            Route::prefix('settings')->name('settings.')->group(function () {
                Route::get('/general', [SettingsController::class, 'general'])->name('general');
                Route::post('/general-save', [SettingsController::class, 'generalSave']);

                Route::get('/openai', [SettingsController::class, 'openai'])->name('openai');
                Route::get('/openai/test', [SettingsController::class, 'openaiTest'])->name('openai.test');
                Route::post('/openai-save', [SettingsController::class, 'openaiSave']);

                Route::get('/stablediffusion', [SettingsController::class, 'stablediffusion'])->name('stablediffusion');
                Route::get('/stablediffusion/test', [SettingsController::class, 'stablediffusionTest'])->name('stablediffusion.test');
                Route::post('/stablediffusion-save', [SettingsController::class, 'stablediffusionSave']);

                Route::get('/unsplashapi', [SettingsController::class, 'unsplashapi'])->name('unsplashapi');
                Route::get('/unsplashapi/test', [SettingsController::class, 'unsplashapiTest'])->name('unsplashapi.test');
                Route::post('/unsplashapi-save', [SettingsController::class, 'unsplashapiSave']);

                Route::get('/serperapi', [SettingsController::class, 'serperapi'])->name('serperapi');
                Route::get('/serperapi/test', [SettingsController::class, 'serperapiTest'])->name('serperapi.test');
                Route::post('/serperapi-save', [SettingsController::class, 'serperapiSave']);

                Route::get('/tts', [SettingsController::class, 'tts'])->name('tts');
                Route::post('/tts-save', [SettingsController::class, 'ttsSave']);

                Route::get('/invoice', [SettingsController::class, 'invoice'])->name('invoice');
                Route::post('/invoice-save', [SettingsController::class, 'invoiceSave']);

                Route::get('/payment', [SettingsController::class, 'payment'])->name('payment');
                Route::post('/payment-save', [SettingsController::class, 'paymentSave']);

                Route::get('/affiliate', [SettingsController::class, 'affiliate'])->name('affiliate');
                Route::post('/affiliate-save', [SettingsController::class, 'affiliateSave']);

                Route::get('/smtp', [SettingsController::class, 'smtp'])->name('smtp');
                Route::post('/smtp-save', [SettingsController::class, 'smtpSave']);
                Route::post('/smtp-test', [SettingsController::class, 'smtpTest'])->name('smtp.test');

                Route::get('/gdpr', [SettingsController::class, 'gdpr'])->name('gdpr');
                Route::post('/gdpr-save', [SettingsController::class, 'gdprSave']);

                Route::get('/privacy', [SettingsController::class, 'privacy'])->name('privacy');
                Route::post('/privacy-save', [SettingsController::class, 'privacySave']);

                Route::post('/get-privacy-terms-content', [SettingsController::class, 'getPrivacyTermsContent']);
                Route::post('/get-meta-content', [SettingsController::class, 'getMetaContent']);

                Route::get('/storage', [SettingsController::class, 'storage'])->name('storage');
                Route::post('/storage-save', [SettingsController::class, 'storagesave']);
            });

            //Affiliates
            Route::prefix('affiliates')->name('affiliates.')->group(function () {
                Route::get('/', [AdminController::class, 'affiliatesList'])->name('index');
                Route::get('/sent/{id}', [AdminController::class, 'affiliatesListSent'])->name('sent');
            });

            //Coupons
            Route::prefix('coupons')->name('coupons.')->group(function () {
                Route::get('/', [AdminController::class, 'couponsList'])->name('index');
                Route::get('/used/{id}', [AdminController::class, 'couponsListUsed'])->name('used');
                Route::get('/delete/{id}', [AdminController::class, 'couponsDelete'])->name('delete');
                Route::post('/edit/{id}', [AdminController::class, 'couponsEdit'])->name('edit');
                Route::post('/add', [AdminController::class, 'couponsAdd'])->name('add');
            });

            //Frontend
            Route::prefix('frontend')->name('frontend.')->group(function () {
                Route::get('/', [AdminController::class, 'frontendSettings'])->name('settings');
                Route::post('/settings-save', [AdminController::class, 'frontendSettingsSave']);

                Route::get('/section-settings', [AdminController::class, 'frontendSectionSettings'])->name('sectionsettings');
                Route::post('/section-settings-save', [AdminController::class, 'frontendSectionSettingsSave']);

                Route::get('/menu', [AdminController::class, 'menuSettings'])->name('menusettings');
                Route::post('/menu-save', [AdminController::class, 'menuSettingsSave']);

                //Frequently Asked Questions (F.A.Q) Section faq
                Route::prefix('faq')->name('faq.')->group(function () {
                    Route::get('/', [AdminController::class, 'frontendFaq'])->name('index');
                    Route::get('/create-or-update/{id?}', [AdminController::class, 'frontendFaqcreateOrUpdate'])->name('createOrUpdate');
                    Route::get('/action/delete/{id}', [AdminController::class, 'frontendFaqDelete'])->name('delete');
                    Route::post('/action/save', [AdminController::class, 'frontendFaqcreateOrUpdateSave']);
                });

                //Tools Section
                Route::prefix('tools')->name('tools.')->group(function () {
                    Route::get('/', [AdminController::class, 'frontendTools'])->name('index');
                    Route::get('/create-or-update/{id?}', [AdminController::class, 'frontendToolscreateOrUpdate'])->name('createOrUpdate');
                    Route::get('/action/delete/{id}', [AdminController::class, 'frontendToolsDelete'])->name('delete');
                    Route::post('/action/save', [AdminController::class, 'frontendToolscreateOrUpdateSave']);
                });

                //Future of ai section Features
                Route::prefix('future')->name('future.')->group(function () {
                    Route::get('/', [AdminController::class, 'frontendFuture'])->name('index');
                    Route::get('/create-or-update/{id?}', [AdminController::class, 'frontendFutureCreateOrUpdate'])->name('createOrUpdate');
                    Route::get('/action/delete/{id}', [AdminController::class, 'frontendFutureDelete'])->name('delete');
                    Route::post('/action/save', [AdminController::class, 'frontendFutureCreateOrUpdateSave']);
                });

                //who is this script for?
                Route::prefix('whois')->name('whois.')->group(function () {
                    Route::get('/', [AdminController::class, 'frontendWhois'])->name('index');
                    Route::get('/create-or-update/{id?}', [AdminController::class, 'frontendWhoisCreateOrUpdate'])->name('createOrUpdate');
                    Route::get('/action/delete/{id}', [AdminController::class, 'frontendWhoisDelete'])->name('delete');
                    Route::post('/action/save', [AdminController::class, 'frontendWhoisCreateOrUpdateSave']);
                });


                //Generator List
                Route::prefix('generatorlist')->name('generatorlist.')->group(function () {
                    Route::get('/', [AdminController::class, 'frontendGeneratorlist'])->name('index');
                    Route::get('/create-or-update/{id?}', [AdminController::class, 'frontendGeneratorlistCreateOrUpdate'])->name('createOrUpdate');
                    Route::get('/action/delete/{id}', [AdminController::class, 'frontendGeneratorlistDelete'])->name('delete');
                    Route::post('/action/save', [AdminController::class, 'frontendGeneratorlistCreateOrUpdateSave']);
                });
            });

            Route::resource('advertis', AdvertisController::class)->parameter('advertis', 'advertis');

            // //Update
            // Route::prefix('update')->name('update.')->group(function () {
            //     Route::get('/', function () {
            //         return view('panel.admin.update.index');
            //     })->name('index');
            // });

            //Healt Page
            Route::prefix('health')->name('health.')->group(function () {
                Route::get('/', function () {
                    $resultStore = App::make(ResultStore::class);
                    $checkResults = $resultStore->latestResults();

                    // call new status when visit the page
                    Artisan::call(RunHealthChecksCommand::class);

                    return view('panel.admin.health.index', [
                        'lastRanAt' => new Carbon($checkResults?->finishedAt),
                        'checkResults' => $checkResults,
                    ]);
                })->name('index');

                Route::get('/logs', function () {
                    return view('panel.admin.health.logs');
                })->name('logs');

                // cache clear
                Route::get('/cache-clear', function () {
                    try {
                        Artisan::call('optimize:clear');
                        return response()->json(['success' => true]);
                    } catch (\Throwable $th) {
                        return response()->json(['success' => false]);
                    }
                })->name('cache.clear');
            });

            //Update license type
            // Route::prefix('license')->name('license.')->group(function () {
            //     Route::get('/', function () {
            //         return view('panel.admin.license.index');
            //     })->name('index');
            // });

            Route::post('translations/auto/{lang}',  [TranslateController::class, 'autoTranslate'])->name('translations.auto');
        });

        //Coupons
        Route::prefix('coupons')->name('coupons.')->group(function () {
            Route::post('/validate-coupon', [AdminController::class, 'couponsValidate'])->name('validate');
        });




        //Support Area
        Route::prefix('support')->name('support.')->group(function () {
            Route::get('/my-requests', [SupportController::class, 'list'])->name('list');
            Route::get('/new-support-request', [SupportController::class, 'newTicket'])->name('new');
            Route::post('/new-support-request/send', [SupportController::class, 'newTicketSend']);

            Route::get('/requests/{ticket_id}', [SupportController::class, 'viewTicket'])->name('view');
            Route::post('/requests-action/send-message', [SupportController::class, 'viewTicketSendMessage']);
        });

        //Pages
        Route::prefix('page')->name('page.')->group(function () {
            Route::get('/', [PageController::class, 'pageList'])->name('list');
            Route::get('/add-or-update/{id?}', [PageController::class, 'pageAddOrUpdate'])->name('addOrUpdate');
            Route::get('/delete/{id?}', [PageController::class, 'pageDelete'])->name('delete');
            Route::post('/save', [PageController::class, 'pageAddOrUpdateSave']);
        });

        //Email Templates
        Route::prefix('email-templates')->name('email-templates.')->group(function () {
            Route::get('/', [EmailTemplatesController::class, 'templateList'])->name('list');
            Route::get('/add-or-update/{id?}', [EmailTemplatesController::class, 'templateAddOrUpdate'])->name('addOrUpdate');
            //Route::get('/delete/{id?}', [EmailTemplatesController::class, 'templateDelete'])->name('delete');
            Route::post('/save', [EmailTemplatesController::class, 'templateAddOrUpdateSave']);
        });

        //Blog
        Route::prefix('blog')->name('blog.')->group(function () {
            Route::get('/', [BlogController::class, 'blogList'])->name('list');
            Route::get('/add-or-update/{id?}', [BlogController::class, 'blogAddOrUpdate'])->name('addOrUpdate');
            Route::get('/delete/{id?}', [BlogController::class, 'blogDelete'])->name('delete');
            Route::post('/save', [BlogController::class, 'blogAddOrUpdateSave']);
        });

        //Chatbot
        // Route::prefix('chatbot')->name('chatbot.')->group(function () {
        //     Route::get('/', [ChatBotController::class, 'chatbotIndex'])->name('index');
        //     Route::get('/add-or-update/{id?}', [ChatBotController::class, 'addOrUpdate'])->name('addOrUpdate');
        //     Route::get('/delete/{id?}', [ChatBotController::class, 'delete'])->name('delete');
        //     Route::post('/save', [ChatBotController::class, 'addOrUpdateSave']);
        //     Route::post('/save-settings', [ChatBotController::class, 'chatbotSettingsSave']);
        // });

        //Search
        Route::post('/api/search', [SearchController::class, 'search']);
    });

    // Override elseyyid routes
    Route::group(['prefix' => config('elseyyid-location.prefix'), 'middleware' => config('elseyyid-location.middlewares'), 'as' => 'elseyyid.translations.'], function () {
        Route::get('home', '\Elseyyid\LaravelJsonLocationsManager\Controllers\HomeController@index')->name('home');
        Route::get('lang/{lang}', '\Elseyyid\LaravelJsonLocationsManager\Controllers\HomeController@lang')->name('lang');
        Route::get('lang/generateJson/{lang}', '\Elseyyid\LaravelJsonLocationsManager\Controllers\HomeController@generateJson')->name('lang.generateJson');
        Route::get('newLang', '\Elseyyid\LaravelJsonLocationsManager\Controllers\HomeController@newLang')->name('lang.newLang');
        Route::get('newString', '\Elseyyid\LaravelJsonLocationsManager\Controllers\HomeController@newString')->name('lang.newString');
        Route::get('search', '\Elseyyid\LaravelJsonLocationsManager\Controllers\HomeController@search')->name('lang.search');
        Route::get('string/{code}', '\Elseyyid\LaravelJsonLocationsManager\Controllers\HomeController@string')->name('lang.string');
        Route::get('publish-all', '\Elseyyid\LaravelJsonLocationsManager\Controllers\HomeController@publishAll')->name('lang.publishAll');

        //Reinstall
        Route::get('regenerate', function () {
            Artisan::call('elseyyid:location:install');
            return redirect()->route('elseyyid.translations.home')->with(config('elseyyid-location.message_flash_variable'), __('Language files regenerated!'));
        })->name('lang.reinstall');

        //setLocale
        Route::get('setLocale', function (\Illuminate\Http\Request $request) {
            $settings_two = \App\Models\SettingTwo::first();
            $settings_two->languages_default = $request->setLocale;
            $settings_two->save();
            LaravelLocalization::setLocale($request->setLocale);
            return redirect()->route('elseyyid.translations.home', [$request->setLocale])->with(config('elseyyid-location.message_flash_variable'), $request->setLocale);
        })->name('lang.setLocale');
    });

    Route::post('translations/lang/update/{id}', '\Elseyyid\LaravelJsonLocationsManager\Controllers\HomeController@update')->name('elseyyid.translations.lang.update');
    Route::post('translations/lang/update-all', function (\Illuminate\Http\Request $request) {
        $json = json_decode($request->data, true);
        $column_name = $request->lang;
        foreach ($json as $code => $column_value) {
            ++$code;
            if (!empty($column_value)) {
                $test = \Elseyyid\LaravelJsonLocationsManager\Models\Strings::where('code', '=', $code)->update([$column_name => $column_value]);
            }
        }
        $lang = $column_name;
        $list = \Elseyyid\LaravelJsonLocationsManager\Models\Strings::pluck($lang, 'en');
        
        $new_json = json_encode_prettify($list);
        $filesystem = new \Illuminate\Filesystem\Filesystem;
        $filesystem->put(base_path('lang/' . $lang . '.json'), $new_json);

        if ($column_name == 'edit') {
            // Read existing values from en.json
            $enJsonPath = base_path('lang/en.json');
            $existingJson = $filesystem->get($enJsonPath);
            $existingValues = json_decode($existingJson, true);
            // Read non-empty values from edit.json
            $editJsonPath = base_path('lang/edit.json');
            $editJson = $filesystem->get($editJsonPath);
            $editValues = json_decode($editJson, true);
            // Update values in en.json using keys from edit.json
            foreach ($editValues as $key => $column_value) {
                // Check if the value is not empty
                if (!empty($column_value)) {
                    // Update the existing values with non-empty values using the key from edit.json
                    $existingValues[$key] = $column_value;
                }
            }
            // Convert the updated values to JSON
            $updatedJson = json_encode_prettify($existingValues);
            // Write the updated JSON to en.json
            $filesystem->put($enJsonPath, $updatedJson);
        }
        return response()->json(['code' => 200], 200);
    })->name('elseyyid.translations.lang.update-all');
    

    Route::post('translations/lang-save', function (\Illuminate\Http\Request $request) {

        $settings_two = \App\Models\SettingTwo::first();
        $codes = explode(',', $settings_two->languages);

        if ($request->state) {
            if (!in_array($request->lang, $codes)) {
                $codes[] = $request->lang;
            }
        } else {
            if (in_array($request->lang, $codes)) {
                unset($codes[array_search($request->lang, $codes)]);
            }
        }
        $settings_two->languages = implode(',', $codes);
        $settings_two->save();
        return response()->json(['code' => 200], 200);
    })->name('elseyyid.translations.lang.lang-save');

    Route::post('image/upload', function (\Illuminate\Http\Request $request) {
        $image = $request->file('image');
        $title = $request->input('title');

        $imageContent = file_get_contents($image->getRealPath());
        $base64Image = base64_encode($imageContent);
        $nameOfImage = Str::random(12) . ".png";

        //save file on local storage or aws s3
        Storage::disk('public')->put($nameOfImage, base64_decode($base64Image));
        $path = '/uploads/' . $nameOfImage;
        $uploadedFile = new File(substr($path, 1));

        if (SettingTwo::first()->ai_image_storage == "s3") {
            try {
                error_log('1');
                $aws_path = Storage::disk('s3')->put('', $uploadedFile);
                error_log('1');
                unlink(substr($path, 1));
                error_log('1');
                $path = Storage::disk('s3')->url($aws_path);
            } catch (\Exception $e) {
                return response()->json(["status" => "error", "message" => "AWS Error - " . $e->getMessage()]);
            }
        }
        return response()->json(['path' => "$path"]);
    })->name('upload.image');

    Route::post('images/upload', function (\Illuminate\Http\Request $request) {
        $images = $request->input('images');

        $paths = [];

        foreach ($images ?? [] as $image) {
            $base64Image = $image;
            error_log($image);
            $nameOfImage = Str::random(12) . ".png";

            //save file on local storage or aws s3
            Storage::disk('public')->put($nameOfImage, base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $base64Image)));
            $path = '/uploads/' . $nameOfImage;
            $uploadedFile = new File(substr($path, 1));

            if (SettingTwo::first()->ai_image_storage == "s3") {
                try {
                    $aws_path = Storage::disk('s3')->put('', $uploadedFile);
                    unlink(substr($path, 1));
                    $path = Storage::disk('s3')->url($aws_path);
                } catch (\Exception $e) {
                    return response()->json(["status" => "error", "message" => "AWS Error - " . $e->getMessage()]);
                }
            }

            array_push($paths, $path);
        }
        return response()->json(['path' => $paths]);
    })->name('upload.images');

    Route::post('pdf/upload', [ChatPdfController::class, 'uploadPDF'])->name('upload.pdf');
    Route::post('pdf/getContent', [ChatPdfController::class, 'getSimiliarContent'])->name('pdf.getcontent');

    Route::post('rss/fetch', function (\Illuminate\Http\Request $request) {
        $data = parseRSS( $request->url );
        if ( is_array($data) && $data ) {
            $html = '';
            foreach( $data as $post ) {
                $html .= sprintf( 
                    '<option value="%1$s" data-image="%2$s">%1$s</option>',
                    e( $post['title'] ),
                    e( $post['image'] ),
                );
            }
            return response()->json($html, 200);
        } else {
            return response()->json(__('RSS Not Fetched! Please check your URL and validete the RSS!'), 419);
        }
    })->name('rss.fetch');

    // if (file_exists(base_path('routes/custom_routes_panel.php'))) {
    //     include base_path('routes/custom_routes_panel.php');
    // }
    $files = glob(base_path('routes/extroutes/*.php'));
    for ($i = 0; $i < count($files); $i++) {
        error_log($files[$i]);
        include($files[$i]);
    }

    Route::middleware('auth')->group(function () {
        Route::middleware('admin')->get('/debug', function () {
            $currentDebugValue = env('APP_DEBUG', false);
            $newDebugValue = !$currentDebugValue;
            $envContent = file_get_contents(base_path('.env'));
            $envContent = preg_replace('/^APP_DEBUG=.*/m', "APP_DEBUG=" . ($newDebugValue ? 'true' : 'false'), $envContent);
            file_put_contents(base_path('.env'), $envContent);
            Artisan::call('config:clear');
            return redirect()->back()->with('message', 'Debug mode updated successfully.');
        });
    });
});
