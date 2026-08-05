<?php

use App\Http\Controllers\ProductController;
use App\Http\Controllers\LeadDocumentController;
use App\Http\Controllers\AiAgentController;
use App\Http\Controllers\AllowedOriginController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\ApiRequestLogController;
use App\Http\Controllers\FunnelController;
use App\Http\Controllers\FunnelManagerQueueController;
use App\Http\Controllers\LostReasonController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BusinessProfileController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\OptusController;
use App\Http\Controllers\LeadArchiveController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ManagerUserController;
use App\Http\Controllers\ConversationReportController;
use App\Http\Controllers\CustomProductFieldController;
use App\Http\Controllers\CustomProductFieldGroupController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\LeadCustomFieldController;
use App\Http\Controllers\EntryRequirementController;
use App\Http\Controllers\FacebookMessengerIntegrationController;
use App\Http\Controllers\GoogleAdsIntegrationController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\InstagramIntegrationController;
use App\Http\Controllers\LeadCompanyController;
use App\Http\Controllers\LeadAppointmentController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\MetaAdsIntegrationController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\N8nAgentController;
use App\Http\Controllers\N8nIntegrationController;
use App\Http\Controllers\OpenAiIntegrationController;
use App\Http\Controllers\ProductImageController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StageController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TaskActivityController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskListController;
use App\Http\Controllers\TaskSpaceController;
use App\Http\Controllers\CalendarBookingController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\PublicBookingController;
use App\Http\Controllers\QuickstartController;
use App\Http\Controllers\UserPreferenceController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\LeadProductController;
use App\Http\Controllers\ObservationController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\InternalMessageController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleDocController;
use App\Http\Controllers\ChatSessionController;
use App\Http\Controllers\ClosingMessageController;
use App\Http\Controllers\ClosingReasonController;
use App\Http\Controllers\ConversationMetricsController;
use App\Http\Controllers\ConversationSettingController;
use App\Http\Controllers\InboxBulkActionController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\WelcomeMessageConfigController;
use App\Http\Controllers\WhatsAppEvoIntegrationController;
use App\Http\Controllers\WhatsAppInstanceController;
use App\Http\Controllers\WidgetController;
use App\Http\Controllers\WidgetIntegrationController;
use App\Http\Controllers\PublicWidgetController;
use App\Http\Controllers\PublicWidgetChatController;
use App\Http\Controllers\NotificationSettingController;
use App\Http\Controllers\OptomyzeErpIntegrationController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AppointmentTypeController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\TeamController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Handle CORS preflight for all API routes
Route::options('{any}', function () {
    return response()->json([], 200);
})->where('any', '.*');


Route::post('/auth/login', [AuthController::class, 'login']);
// Step two of a multi-company sign-in. Unauthenticated by design: the caller
// holds a short-lived selection token, not a session.
Route::post('/auth/select-company', [AuthController::class, 'selectCompany']);
Route::post('/auth/forgot-pwd', [AuthController::class, 'forgotPassword']);
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

Route::post('/users/manager', [UserController::class, 'storeManager']);

/*
|--------------------------------------------------------------------------
| Public Widget Routes (embed script — no authentication)
|--------------------------------------------------------------------------
| Hit directly by visitors' browsers on customer websites. Access is bounded
| by the widget's own allowed-domain list plus per-IP rate limiting.
*/
/*
| Public booking pages. Unauthenticated by design — the slug is the credential,
| so every response is narrow and each route is throttled per IP.
*/
Route::prefix('public/booking')->group(function () {
    Route::get('/{slug}', [PublicBookingController::class, 'show'])
        ->middleware('throttle:60,1');
    Route::get('/{slug}/availability', [PublicBookingController::class, 'availability'])
        ->middleware('throttle:60,1');
    Route::post('/{slug}/book', [PublicBookingController::class, 'store'])
        ->middleware('throttle:10,1');
});

Route::prefix('public/widgets')->group(function () {
    Route::get('/{publicId}', [PublicWidgetController::class, 'config'])
        ->middleware('throttle:60,1');
    Route::post('/{publicId}/submit', [PublicWidgetController::class, 'submit'])
        ->middleware('throttle:10,1');

    // Website chat widget
    Route::post('/{publicId}/chat/session', [PublicWidgetChatController::class, 'session'])
        ->middleware('throttle:30,1');
    Route::post('/{publicId}/chat/identify', [PublicWidgetChatController::class, 'identify'])
        ->middleware('throttle:20,1');
    Route::post('/{publicId}/chat/messages', [PublicWidgetChatController::class, 'send'])
        ->middleware('throttle:30,1');
    Route::get('/{publicId}/chat/messages', [PublicWidgetChatController::class, 'messages'])
        ->middleware('throttle:240,1');
});

/*
|--------------------------------------------------------------------------
| Company Management Routes (Manager Key required)
|--------------------------------------------------------------------------
*/
Route::middleware('verify.manager.key')->prefix('manager')->group(function () {
    // Company management
    Route::get('/companies', [CompanyController::class, 'index']);
    Route::post('/companies', [CompanyController::class, 'store']);
    Route::get('/companies/{id}', [CompanyController::class, 'show']);
    Route::put('/companies/{id}', [CompanyController::class, 'update']);
    Route::delete('/companies/{id}', [CompanyController::class, 'destroy']);
    Route::post('/companies/{id}/restore', [CompanyController::class, 'restore']);

    // User management
    Route::get('/users', [ManagerUserController::class, 'index']);
    Route::post('/users', [ManagerUserController::class, 'store']);
    Route::get('/users/{id}', [ManagerUserController::class, 'show']);
    Route::put('/users/{id}', [ManagerUserController::class, 'update']);
    Route::patch('/users/{id}/deactivate', [ManagerUserController::class, 'deactivate']);
    Route::delete('/users/{id}', [ManagerUserController::class, 'destroy']);
});


Route::middleware('auth:sanctum')->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Auth Routes
    |--------------------------------------------------------------------------
    */
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/update', [AuthController::class, 'updateProfile']);
    Route::post('/auth/reset/password', [AuthController::class, 'resetPasswordAuth']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Multi-company sessions: the companies this person may act in, and moving
    // the session to one of them.
    Route::get('/auth/companies', [AuthController::class, 'companies']);
    Route::post('/auth/switch-company', [AuthController::class, 'switchCompany']);


    /*
    |--------------------------------------------------------------------------
    | User Management Routes
    |--------------------------------------------------------------------------
    */
    /*
    |--------------------------------------------------------------------------
    | User Preferences — a singleton for the authenticated user
    |--------------------------------------------------------------------------
    | Declared before /users/{id} so the wildcard cannot swallow it.
    */
    Route::get('/user/preferences', [UserPreferenceController::class, 'show']);
    Route::put('/user/preferences', [UserPreferenceController::class, 'update']);
    Route::delete('/user/preferences', [UserPreferenceController::class, 'destroy']);

    // Quickstart onboarding checklist — one per company, resolved from the
    // authenticated user's company rather than an id in the path.
    Route::get('/quickstart', [QuickstartController::class, 'show']);
    Route::patch('/quickstart/steps/{step}', [QuickstartController::class, 'updateStep']);
    Route::post('/quickstart/dismiss', [QuickstartController::class, 'dismiss']);

    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/search', [UserController::class, 'search']);
    Route::get('/users/{id}/performance', [UserController::class, 'performance']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::patch('/users/{id}/toggle-status', [UserController::class, 'toggleStatus']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);


    /*
    |--------------------------------------------------------------------------
    | Team Management Routes
    |--------------------------------------------------------------------------
    */
    Route::get('/teams', [TeamController::class, 'index']);
    Route::post('/teams', [TeamController::class, 'store']);
    Route::get('/teams/{team}', [TeamController::class, 'show']);
    Route::put('/teams/{team}', [TeamController::class, 'update']);
    Route::delete('/teams/{team}', [TeamController::class, 'destroy']);


    /*
    |--------------------------------------------------------------------------
    | Lost Reasons Routes
    |--------------------------------------------------------------------------
    */
    Route::get('/lost-reasons', [LostReasonController::class, 'index']);
    Route::post('/lost-reasons', [LostReasonController::class, 'store']);
    Route::put('/lost-reasons/{lostReason}', [LostReasonController::class, 'update']);
    Route::delete('/lost-reasons/{lostReason}', [LostReasonController::class, 'destroy']);


    /*
    |--------------------------------------------------------------------------
    | Funnel Manager Queues Routes
    |--------------------------------------------------------------------------
    */
    Route::get('/funnel-manager-queues', [FunnelManagerQueueController::class, 'index']);
    Route::post('/funnel-manager-queues', [FunnelManagerQueueController::class, 'store']);
    Route::get('/funnel-manager-queues/{funnelManagerQueue}', [FunnelManagerQueueController::class, 'show']);
    Route::put('/funnel-manager-queues/{funnelManagerQueue}', [FunnelManagerQueueController::class, 'update']);
    Route::delete('/funnel-manager-queues/{funnelManagerQueue}', [FunnelManagerQueueController::class, 'destroy']);


    /*
    |--------------------------------------------------------------------------
    | Funnels & Stages Routes
    |--------------------------------------------------------------------------
    */
    // Funnels for the sidebar/menu — respects is_active and the company's
    // contact-pipeline switch.
    Route::get('/funnels/navigation', [FunnelController::class, 'navigationFunnels']);
    Route::get('/funnels', [FunnelController::class, 'index']);
    Route::get('/funnels/templates', [FunnelController::class, 'templates']);
    Route::get('/funnels/deals', [FunnelController::class, 'deals']);
    Route::post('/funnels', [FunnelController::class, 'store']);
    Route::get('/funnels/{id}', [FunnelController::class, 'leadsByStages']);
    Route::get('/funnels/{id}/deals', [FunnelController::class, 'dealsByStages']);
    Route::put('/funnels/{id}', [FunnelController::class, 'update']);
    Route::patch('/funnels/{id}/toggle-status', [FunnelController::class, 'toggleStatus']);
    // Read first, so the confirmation can name what the delete costs.
    Route::get('/funnels/{id}/delete-impact', [FunnelController::class, 'deleteImpact']);
    Route::delete('/funnels/{id}', [FunnelController::class, 'destroy']);

    // Infinite scroll: one page of leads/deals for a single board column.
    Route::get('/funnels/{id}/stages/{stageId}/leads', [FunnelController::class, 'stageLeads']);
    Route::get('/funnels/{id}/stages/{stageId}/deals', [FunnelController::class, 'stageDeals']);

    Route::get('/funnels/{funnelId}/stages', [StageController::class, 'index']);
    Route::post('/funnels/{funnelId}/stages', [StageController::class, 'store']);
    Route::get('/funnels/{funnelId}/stages/{stageId}', [StageController::class, 'show']);
    Route::put('/funnels/{funnelId}/stages/{stageId}', [StageController::class, 'update']);
    Route::delete('/funnels/{funnelId}/stages/{stageId}', [StageController::class, 'destroy']);
    Route::put('/funnels/{funnelId}/stages/{stageId}/move', [StageController::class, 'moveOrder']);

    Route::patch('/stages/{stage}/entry-requirements',[EntryRequirementController::class, 'update']);


    /*
    |--------------------------------------------------------------------------
    | Leads & Sales Routes
    |--------------------------------------------------------------------------
    */
    Route::get('/leads', [LeadController::class, 'index']);
    /*
    |--------------------------------------------------------------------------
    | Lead archiving & bulk actions
    |--------------------------------------------------------------------------
    | Declared before the /leads/{lead}/... routes: a wildcard segment would
    | otherwise match the literal "bulk" and shadow these.
    | Archived leads (is_active = false) are hidden by default everywhere.
    */
    Route::post('/leads/bulk/archive', [LeadArchiveController::class, 'bulkArchive']);
    Route::post('/leads/bulk/assign', [LeadArchiveController::class, 'bulkAssign']);
    Route::post('/leads/bulk/tag', [LeadArchiveController::class, 'bulkTag']);
    Route::post('/leads/bulk/move-funnel', [LeadArchiveController::class, 'bulkMoveToFunnel']);
    Route::patch('/leads/{id}/archive', [LeadArchiveController::class, 'archive']);
    Route::patch('/leads/{id}/unarchive', [LeadArchiveController::class, 'unarchive']);

    Route::post('/leads', [LeadController::class, 'store']);
    Route::get('/leads/search/{products?}', [LeadController::class, 'search']);
    Route::get('/leads/{lead}', [LeadController::class, 'show']);
    Route::put('/leads/{lead}', [LeadController::class, 'update']);
    Route::delete('/leads/{lead}', [LeadController::class, 'destroy']);
    Route::post('/leads/{lead}/move-stage', [LeadController::class, 'moveToStage']);
    Route::post('/leads/{lead}/move-funnel', [LeadController::class, 'moveToFunnel']);
    Route::put('/leads/{id}/tags', [LeadController::class, 'updateTags']);
    Route::post('/leads/{lead}/assign', [LeadController::class, 'assignUser']);
    Route::post('/leads/{lead}/lost-reason', [LeadController::class, 'recordLostReason']);
    Route::post('leads/{lead}/add-erp-budget', [LeadController::class, 'addErpBudget']);
    Route::get('/leads-activity/transactions', [LeadController::class, 'leadTransactions']);
    Route::get('/leads-activity/tasks', [LeadController::class, 'leadTasks']);

    Route::post('/leads/{lead}/products', [LeadProductController::class, 'store']);
    Route::post('/leads/{lead}/sales', [SaleController::class, 'store']);
    Route::get('/sales/search', [SaleController::class, 'search']);
    Route::delete('/sales/{sale}', [SaleController::class, 'destroy']);

    Route::post('/sales/{sale}/docs', [SaleDocController::class, 'store']);
    Route::delete('/sales/docs/{doc}', [SaleDocController::class, 'destroy']);
    Route::get('/sales/{sale}', [SaleController::class, 'show']);
    Route::put('/sales/{sale}', [SaleController::class, 'update']);
    Route::put('/sales/{sale}/tags', [SaleController::class, 'updateTags']);
    Route::post('/sales/{sale}/assign', [SaleController::class, 'assignUser']);
    // Archiving hides a deal from the board without the cascade that
    // deleting one performs; move-funnel lands it on the target's entry stage.
    Route::patch('/sales/{sale}/archive', [SaleController::class, 'setArchived']);
    Route::post('/sales/{sale}/move-funnel', [SaleController::class, 'moveToFunnel']);
    Route::put('/sales/{sale}/status', [SaleController::class, 'updateStatus']);
    Route::patch('/sales/{sale}/move-stage', [SaleController::class, 'moveToStage']);
    Route::get('/deals-activity/transactions', [SaleController::class, 'dealTransactions']);

    /*
    |--------------------------------------------------------------------------
    | Observations (Lead Notes)
    |--------------------------------------------------------------------------
    */
    Route::get('/leads/{lead}/observations', [ObservationController::class, 'index']);
    // Notes on anything: type is 'lead' or 'deal'. Threaded replies included.
    Route::get('/notes/{type}/{id}', [ObservationController::class, 'indexFor']);
    Route::post('/observations', [ObservationController::class, 'store']);
    Route::put('/observations/{observation}', [ObservationController::class, 'update']);
    Route::delete('/observations/{observation}', [ObservationController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | Appointment Types Routes
    |--------------------------------------------------------------------------
    */
    // Calendars — a company owns many; managed from the Calendar screen.
    Route::get('/calendars', [CalendarController::class, 'index']);
    Route::post('/calendars', [CalendarController::class, 'store']);
    Route::get('/calendars/{calendar}', [CalendarController::class, 'show']);
    Route::put('/calendars/{calendar}', [CalendarController::class, 'update']);
    Route::patch('/calendars/{calendar}/default', [CalendarController::class, 'setDefault']);
    Route::delete('/calendars/{calendar}', [CalendarController::class, 'destroy']);

    // Public booking configuration, managed from the Calendar module.
    Route::get('/calendars/{calendar}/booking', [CalendarBookingController::class, 'show']);
    Route::put('/calendars/{calendar}/booking', [CalendarBookingController::class, 'update']);
    Route::post('/calendars/{calendar}/booking/slug', [CalendarBookingController::class, 'regenerateSlug']);
    Route::get('/calendars/{calendar}/booking/availability', [CalendarBookingController::class, 'previewAvailability']);

    Route::get('/appointment-types', [AppointmentTypeController::class, 'index']);
    Route::post('/appointment-types', [AppointmentTypeController::class, 'store']);
    Route::put('/appointment-types/{appointmentType}', [AppointmentTypeController::class, 'update']);
    Route::delete('/appointment-types/{appointmentType}', [AppointmentTypeController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | Appointments Routes
    |--------------------------------------------------------------------------
    */
    Route::get('/leads/{lead}/appointments', [AppointmentController::class, 'index']);
    Route::get('/appointments', [AppointmentController::class, 'companyIndex']);
    Route::post('/appointments', [AppointmentController::class, 'store']);
    Route::put('/appointments/{appointment}', [AppointmentController::class, 'update']);
    Route::delete('/appointments/{appointment}', [AppointmentController::class, 'destroy']);
    Route::post('/appointments/{appointment}/products/sync', [AppointmentController::class, 'syncProducts']);

    /*
    |--------------------------------------------------------------------------
    | Tags & Tokens Routes
    |--------------------------------------------------------------------------
    */
    Route::get('/tags', [TagController::class, 'index']);
    Route::post('/tags', [TagController::class, 'store']);
    Route::put('/tags/{id}', [TagController::class, 'update']);
    Route::delete('/tags/{id}', [TagController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | Embeddable Widgets (management side)
    |--------------------------------------------------------------------------
    */
    Route::get('/widgets/options', [WidgetController::class, 'options']);
    Route::get('/widgets', [WidgetController::class, 'index']);
    Route::post('/widgets', [WidgetController::class, 'store']);
    Route::get('/widgets/{id}', [WidgetController::class, 'show']);
    Route::put('/widgets/{id}', [WidgetController::class, 'update']);
    Route::delete('/widgets/{id}', [WidgetController::class, 'destroy']);
    Route::post('/widgets/{id}/duplicate', [WidgetController::class, 'duplicate']);
    Route::patch('/widgets/{id}/status', [WidgetController::class, 'updateStatus']);
    Route::post('/widgets/{id}/regenerate-public-id', [WidgetController::class, 'regeneratePublicId']);

    Route::get('/tokens', [ApiTokenController::class, 'index']);
    Route::post('/tokens', [ApiTokenController::class, 'generate']);
    Route::put('/tokens/{id}', [ApiTokenController::class, 'update']);
    Route::delete('/tokens/{id}', [ApiTokenController::class, 'destroy']);

    Route::get('/allowed-origins', [AllowedOriginController::class, 'index']);
    Route::post('/allowed-origins', [AllowedOriginController::class, 'store']);
    Route::put('/allowed-origins/{id}', [AllowedOriginController::class, 'update']);
    Route::delete('/allowed-origins/{id}', [AllowedOriginController::class, 'destroy']);

    Route::get('/api-logs', [ApiRequestLogController::class, 'index']);
    Route::get('/api-logs/filter-options', [ApiRequestLogController::class, 'filterOptions']);
    Route::get('/api-logs/{id}', [ApiRequestLogController::class, 'show']);


    /*
    |--------------------------------------------------------------------------
    | Records & Metrics Routes
    |--------------------------------------------------------------------------
    */
    Route::get('/records', [RecordController::class, 'index']);
    Route::get('/records-activity', [RecordController::class, 'activity']);
    Route::get('/companies/{companyId}/records', [RecordController::class, 'byCompany']);

    Route::get('/metrics/leads-breakdown', [MetricsController::class, 'dashboard']);
    Route::get('/metrics/monthly-charts', [MetricsController::class, 'monthlyCharts']);
    Route::get('/metrics/plan-usage', [MetricsController::class, 'planUsage']);


    /*
    |--------------------------------------------------------------------------
    | AI Agents Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('ai-agents')->group(function () {
        Route::get('/', [AiAgentController::class, 'index']);
        Route::post('/', [AiAgentController::class, 'store']);
        Route::get('/tools', [AiAgentController::class, 'tools']);
        Route::get('/channel-accounts', [AiAgentController::class, 'channelAccounts']);
        Route::post('/preview-chat', [AiAgentController::class, 'previewChat']);
        Route::put('/{id}', [AiAgentController::class, 'update']);
        Route::put('/{id}/status', [AiAgentController::class, 'updateStatus']);
        Route::put('/{id}/channels', [AiAgentController::class, 'syncChannels']);
        Route::get('/{id}/executions', [AiAgentController::class, 'executions']);
        Route::delete('/{id}', [AiAgentController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | Integrations Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('integrations')->group(function () {

        // Available integrations
        Route::get('/available', [IntegrationController::class, 'available']);
        Route::get('/available/settings', [IntegrationController::class, 'availableWithSettings']);

        // Website widgets — installed per type, like every other integration.
        Route::post('/{integrationKey}', [WidgetIntegrationController::class, 'createIntegration'])
            ->where('integrationKey', 'form-widget|chat-widget');

        // N8N Integrations
        Route::post('/n8n-integrations', [N8nIntegrationController::class, 'createIntegration']);
        Route::put('/n8n-integrations/configure', [N8nIntegrationController::class, 'configure']);
        Route::get('/n8n-integrations/data', [N8nIntegrationController::class, 'configureData']);
        Route::get('/n8n/workflows', [N8nIntegrationController::class, 'fetchWorkflows']);

        // Meta Ads Integration
        Route::post('/meta-ads', [MetaAdsIntegrationController::class, 'createIntegration']);
        Route::put('/meta-ads/configure', [MetaAdsIntegrationController::class, 'configure']);
        Route::get('/meta-ads/fetch-meta-data', [MetaAdsIntegrationController::class, 'fetchMetaData']);

        // Google Ads Integration
        Route::post('/google-ads', [GoogleAdsIntegrationController::class, 'createIntegration']);
        Route::put('/google-ads/configure', [GoogleAdsIntegrationController::class, 'configure']);
        Route::get('/google-ads/fetch-google-data', [GoogleAdsIntegrationController::class, 'fetchGoogleData']);

        // Optomyze ERP Integration
        Route::post('/optomyze-erp', [OptomyzeErpIntegrationController::class, 'createIntegration']);
        Route::put('/optomyze-erp/configure', [OptomyzeErpIntegrationController::class, 'configure']);
        Route::get('/optomyze-erp/api-key', [OptomyzeErpIntegrationController::class, 'getApiKey']);

        // N8N Agents
        Route::get('/agents/n8n', [N8nAgentController::class, 'index']);
        Route::get('/agents/n8n/executions/{agent}', [N8nAgentController::class, 'fetchExecutions']);
        Route::get('/agents/n8n/prompt/{agent}', [N8nAgentController::class, 'fetchAiAgentSystemPrompt']);

        // WhatsApp Evolution API
        Route::post('/whatsapp-evo-integrations', [WhatsAppEvoIntegrationController::class, 'createIntegration']);
        Route::put('/whatsapp-evo-integrations/configure', [WhatsAppEvoIntegrationController::class, 'configure']);

        // WhatsApp Evolution API Instances
        Route::get('/whatsapp-evo-instances', [WhatsAppInstanceController::class, 'index']);
        Route::post('/whatsapp-evo-instances', [WhatsAppInstanceController::class, 'createInstance']);
        Route::delete('/whatsapp-evo-instances/{instanceId}', [WhatsAppInstanceController::class, 'deleteInstance']);
        Route::get('/whatsapp-evo-instances/connection-status', [WhatsAppInstanceController::class, 'connectionStatusAll']);
        Route::get('/whatsapp-evo-instances/connection-status/{instanceId}', [WhatsAppInstanceController::class, 'connectionStatus']);
        Route::get('/whatsapp-evo-instances/qrcode/{instanceId}', [WhatsAppInstanceController::class, 'generateQrCode']);
        Route::post('/whatsapp-evo-instances/send-message/{instanceId}', [WhatsAppInstanceController::class, 'sendMessage']);
        Route::post('/whatsapp-evo-instances/disconnect/{instanceId}', [WhatsAppInstanceController::class, 'disconnectInstance']);
        Route::get('/whatsapp-evo-instances/{instanceId}/disconnect-info', [WhatsAppInstanceController::class, 'disconnectInfo']);
        Route::put('/whatsapp-evo-integrations/{instance}/funnel', [WhatsAppInstanceController::class, 'updateFunnel']);

        // Instagram Integration (Instagram Graph API)
        Route::post('/instagram', [InstagramIntegrationController::class, 'createIntegration']);
        Route::get('/instagram', [InstagramIntegrationController::class, 'show']);
        Route::put('/instagram/configure', [InstagramIntegrationController::class, 'configure']);
        Route::post('/instagram/test-connection', [InstagramIntegrationController::class, 'testConnection']);
        Route::post('/instagram/disconnect', [InstagramIntegrationController::class, 'disconnectAccount']);
        Route::put('/instagram/funnel', [InstagramIntegrationController::class, 'updateFunnel']);

        // OpenAI (ChatGPT) Integration
        Route::post('/openai', [OpenAiIntegrationController::class, 'createIntegration']);
        Route::get('/openai', [OpenAiIntegrationController::class, 'show']);
        Route::put('/openai/configure', [OpenAiIntegrationController::class, 'configure']);
        Route::post('/openai/test-connection', [OpenAiIntegrationController::class, 'testConnection']);
        Route::get('/openai/models', [OpenAiIntegrationController::class, 'listModels']);
        Route::post('/openai/disconnect', [OpenAiIntegrationController::class, 'disconnect']);

        // Facebook Messenger Integration (Facebook Graph API, Page access token)
        Route::post('/facebook', [FacebookMessengerIntegrationController::class, 'createIntegration']);
        Route::get('/facebook', [FacebookMessengerIntegrationController::class, 'show']);
        Route::put('/facebook/configure', [FacebookMessengerIntegrationController::class, 'configure']);
        Route::post('/facebook/test-connection', [FacebookMessengerIntegrationController::class, 'testConnection']);
        Route::post('/facebook/disconnect', [FacebookMessengerIntegrationController::class, 'disconnectAccount']);
        Route::put('/facebook/funnel', [FacebookMessengerIntegrationController::class, 'updateFunnel']);

    });

    /*
    |--------------------------------------------------------------------------
    | Global Inbox Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('inbox')->group(function () {
        Route::get('/chats', [InboxController::class, 'getCompanyChats']);
        Route::get('/leads/{lead}/chat', [InboxController::class, 'getLeadChat']);
        Route::get('/chats/{chat}/messages', [InboxController::class, 'getChatMessages']);
        Route::put('/chats/{chat}/ai-agent-service', [InboxController::class, 'updateAiAgentService']);
        // Read state, archiving and favouriting for a conversation.
        Route::post('/chats/{chat}/read', [InboxController::class, 'markChatRead']);
        Route::patch('/chats/{chat}/archived', [InboxController::class, 'setChatArchived']);
        Route::patch('/chats/{chat}/favorite', [InboxController::class, 'setChatFavorite']);
        Route::delete('/chats/{chat}/messages', [InboxController::class, 'clearMessages']);

        /*
        | The same four actions over a selection. POST throughout, including
        | the destructive ones: the ids travel in the body, and a DELETE with a
        | body is the kind of thing proxies drop.
        */
        Route::post('/chats/bulk/archived', [InboxBulkActionController::class, 'archive']);
        Route::post('/chats/bulk/favorite', [InboxBulkActionController::class, 'favorite']);
        Route::post('/chats/bulk/clear-messages', [InboxBulkActionController::class, 'clearMessages']);
        Route::post('/chats/bulk/end', [InboxBulkActionController::class, 'endConversations']);
        Route::post('/chats/{chat}/website-messages', [InboxController::class, 'sendWebsiteChatMessage']);
        Route::post('/instances/{instanceId}/messages', [InboxController::class, 'sendTextMessage']);
        Route::post('/instances/{instanceId}/media', [InboxController::class, 'sendMediaMessage']);
        Route::post('/messages/{message}/reaction', [InboxController::class, 'sendReaction']);
        Route::post('/messages/{message}/transcribe', [InboxController::class, 'transcribeMessage']);
        Route::post('/instagram/{integrationId}/messages', [InboxController::class, 'sendInstagramTextMessage']);
        Route::post('/instagram/{integrationId}/media', [InboxController::class, 'sendInstagramMediaMessage']);
        Route::get('/leads/search', [InboxController::class, 'searchLeads']);
        Route::get('/chats/{chat}/active-session', [ChatSessionController::class, 'active']);
        Route::post('/chats/{chat}/sessions/{session}/close', [ChatSessionController::class, 'close']);
        Route::get('/chats/{chat}/closing-message-preview', [ChatSessionController::class, 'closingMessagePreview']);
    });

    Route::get('/closing-reasons', [ClosingReasonController::class, 'index']);
    Route::post('/closing-reasons', [ClosingReasonController::class, 'store']);
    Route::put('/closing-reasons/{id}', [ClosingReasonController::class, 'update']);
    Route::delete('/closing-reasons/{id}', [ClosingReasonController::class, 'destroy']);
    Route::post('/closing-reasons/reorder', [ClosingReasonController::class, 'reorder']);

    Route::get('/closing-messages', [ClosingMessageController::class, 'index']);
    Route::post('/closing-messages', [ClosingMessageController::class, 'store']);
    Route::put('/closing-messages/{id}', [ClosingMessageController::class, 'update']);
    Route::delete('/closing-messages/{id}', [ClosingMessageController::class, 'destroy']);

    Route::post('/conversation-settings', [ConversationSettingController::class, 'store']);
    Route::patch('/conversation-settings', [ConversationSettingController::class, 'update']);

    Route::get('/welcome-message-configs', [WelcomeMessageConfigController::class, 'index']);
    Route::put('/welcome-message-configs', [WelcomeMessageConfigController::class, 'upsert']);

    Route::get('/conversations-metrics/dashboard', [ConversationMetricsController::class, 'dashboard']);
    Route::get('/conversations-metrics/daily-report', [ConversationMetricsController::class, 'dailyReport']);


    /*
    |--------------------------------------------------------------------------
    | Conversation Reports Routes
    |--------------------------------------------------------------------------
    */
    Route::post('/conversation-reports', [ConversationReportController::class, 'store']);
    Route::get('/conversation-reports/lead/{leadId}', [ConversationReportController::class, 'getByLead']);
    Route::get('/conversation-reports/agent/{agentId}', [ConversationReportController::class, 'getByAgent']);


    /*
    |--------------------------------------------------------------------------
    | Tasks Routes
    |--------------------------------------------------------------------------
    */
    // `GET /tasks` keeps its original unpaginated contract — the reports
    // dashboard and the lead-details panel both read it. The workspace's own
    // paginated feed lives at /tasks/feed.
    Route::get('/tasks', [TaskController::class, 'companyTasks']);

    // Static segments must be declared before /tasks/{task}, or the wildcard
    // swallows them and every one of these 404s.
    Route::get('/tasks/feed', [TaskController::class, 'index']);
    Route::get('/tasks/board', [TaskController::class, 'board']);
    Route::get('/tasks/calendar', [TaskController::class, 'calendar']);
    Route::get('/tasks/summary', [TaskController::class, 'summary']);
    Route::get('/tasks/search', [TaskController::class, 'search']);
    Route::post('/tasks/bulk', [TaskController::class, 'bulk']);

    Route::post('/tasks', [TaskController::class, 'store']);
    Route::get('/tasks/{task}', [TaskController::class, 'show']);
    Route::put('/tasks/{task}', [TaskController::class, 'update']);
    Route::patch('/tasks/{task}/move', [TaskController::class, 'move']);
    Route::patch('/tasks/{task}/archive', [TaskController::class, 'archive']);
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy']);

    // Comments, checklist and watch state on a single task.
    Route::get('/tasks/{task}/comments', [TaskActivityController::class, 'comments']);
    Route::post('/tasks/{task}/comments', [TaskActivityController::class, 'storeComment']);
    Route::put('/tasks/{task}/comments/{comment}', [TaskActivityController::class, 'updateComment']);
    Route::delete('/tasks/{task}/comments/{comment}', [TaskActivityController::class, 'destroyComment']);

    Route::post('/tasks/{task}/checklist', [TaskActivityController::class, 'storeChecklistItem']);
    Route::put('/tasks/{task}/checklist/reorder', [TaskActivityController::class, 'reorderChecklist']);
    Route::put('/tasks/{task}/checklist/{item}', [TaskActivityController::class, 'updateChecklistItem']);
    Route::delete('/tasks/{task}/checklist/{item}', [TaskActivityController::class, 'destroyChecklistItem']);

    Route::post('/tasks/{task}/watch', [TaskActivityController::class, 'toggleWatch']);

    /*
    |--------------------------------------------------------------------------
    | Task Workspace — Spaces, Lists, Statuses
    |--------------------------------------------------------------------------
    */
    Route::get('/task-spaces', [TaskSpaceController::class, 'index']);
    Route::post('/task-spaces', [TaskSpaceController::class, 'store']);
    Route::put('/task-spaces/reorder', [TaskSpaceController::class, 'reorder']);
    Route::get('/task-spaces/{space}', [TaskSpaceController::class, 'show']);
    Route::put('/task-spaces/{space}', [TaskSpaceController::class, 'update']);
    Route::patch('/task-spaces/{space}/archive', [TaskSpaceController::class, 'archive']);
    Route::delete('/task-spaces/{space}', [TaskSpaceController::class, 'destroy']);

    Route::post('/task-lists', [TaskListController::class, 'store']);
    Route::put('/task-lists/reorder', [TaskListController::class, 'reorder']);
    Route::get('/task-lists/{list}', [TaskListController::class, 'show']);
    Route::put('/task-lists/{list}', [TaskListController::class, 'update']);
    Route::patch('/task-lists/{list}/archive', [TaskListController::class, 'archive']);
    Route::delete('/task-lists/{list}', [TaskListController::class, 'destroy']);

    Route::post('/task-lists/{list}/statuses', [TaskListController::class, 'storeStatus']);
    Route::put('/task-lists/{list}/statuses/reorder', [TaskListController::class, 'reorderStatuses']);
    Route::put('/task-lists/{list}/statuses/{status}', [TaskListController::class, 'updateStatus']);
    Route::delete('/task-lists/{list}/statuses/{status}', [TaskListController::class, 'destroyStatus']);


    /*
    |--------------------------------------------------------------------------
    | Lead Documents Routes
    |--------------------------------------------------------------------------
    */
    Route::post('/lead-documents', [LeadDocumentController::class, 'store']);
    Route::delete('/lead-documents/{leadDocument}', [LeadDocumentController::class, 'destroy']);


    /*
    |--------------------------------------------------------------------------
    | Lead Companies Routes
    |--------------------------------------------------------------------------
    */
    Route::get('/lead-companies', [LeadCompanyController::class, 'index']);
    Route::post('/lead-companies', [LeadCompanyController::class, 'store']);
    Route::get('/lead-companies/{id}', [LeadCompanyController::class, 'show']);
    Route::put('/lead-companies/{id}', [LeadCompanyController::class, 'update']);
    Route::delete('/lead-companies/{id}', [LeadCompanyController::class, 'destroy']);


    /*
    |--------------------------------------------------------------------------
    | Products & Custom Fields Routes
    |--------------------------------------------------------------------------
    */
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/search', [ProductController::class, 'search']);
    Route::get('/products/get/{product}', [ProductController::class, 'show']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);
    Route::post('/products/{id}/toggle-active', [ProductController::class, 'toggleActive']);
    Route::post('/products/{id}/toggle-favorite', [ProductController::class, 'toggleFavorite']);
    Route::post('/products/{id}/archive', [ProductController::class, 'archive']);
    Route::post('/products/{id}/unarchive', [ProductController::class, 'unarchive']);

    // Product Categories
    Route::get('/product-categories', [ProductCategoryController::class, 'index']);
    Route::post('/product-categories', [ProductCategoryController::class, 'store']);
    Route::get('/product-categories/{id}', [ProductCategoryController::class, 'show']);
    Route::put('/product-categories/{id}', [ProductCategoryController::class, 'update']);
    Route::delete('/product-categories/{id}', [ProductCategoryController::class, 'destroy']);
    Route::post('/products/{productId}/categories/sync', [ProductCategoryController::class, 'syncProductCategories']);

    // Custom Product Field Groups
    Route::get('/custom-product-field-groups', [CustomProductFieldGroupController::class, 'index']);
    Route::post('/custom-product-field-groups', [CustomProductFieldGroupController::class, 'store']);
    Route::get('/custom-product-field-groups/{id}', [CustomProductFieldGroupController::class, 'show']);
    Route::put('/custom-product-field-groups/{id}', [CustomProductFieldGroupController::class, 'update']);
    Route::delete('/custom-product-field-groups/{id}', [CustomProductFieldGroupController::class, 'destroy']);

    Route::prefix('products/{product}')->group(function () {
        Route::get('images', [ProductImageController::class, 'index']);
        Route::post('images', [ProductImageController::class, 'store']);

        Route::post('/custom-fields', [ProductController::class, 'storeCustomField']);
        Route::put('/custom-fields/{fieldKey}', [ProductController::class, 'updateCustomField']);
        Route::delete('/custom-fields/{fieldKey}', [ProductController::class, 'destroyCustomField']);
    });

    Route::put('/product-images/{productImage}', [ProductImageController::class, 'update']);
    Route::post('/product-images/{productImage}/primary', [ProductImageController::class, 'setPrimary']);
    Route::post('/product-images/reorder', [ProductImageController::class, 'reorder']);
    Route::delete('/product-images/{productImage}', [ProductImageController::class, 'destroy']);

    Route::get('/custom-product-fields', [CustomProductFieldController::class, 'index']);
    Route::get('/custom-product-fields/{customProductField}', [CustomProductFieldController::class, 'show']);
    Route::post('/custom-product-fields', [CustomProductFieldController::class, 'store']);
    Route::put('/custom-product-fields/{customProductField}', [CustomProductFieldController::class, 'update']);
    Route::delete('/custom-product-fields/{customProductField}', [CustomProductFieldController::class, 'destroy']);

    // Lead custom fields (model-level defaults)
    Route::get('/lead-custom-fields', [LeadCustomFieldController::class, 'index']);
    Route::post('/lead-custom-fields', [LeadCustomFieldController::class, 'store']);
    Route::put('/lead-custom-fields/{leadCustomField}', [LeadCustomFieldController::class, 'update']);
    Route::delete('/lead-custom-fields/{leadCustomField}', [LeadCustomFieldController::class, 'destroy']);

    // Lead-specific custom field values
    Route::get('/leads/{lead}/custom-fields', [LeadCustomFieldController::class, 'forLead']);
    Route::post('/leads/{lead}/custom-fields', [LeadCustomFieldController::class, 'storeForLead']);
    Route::delete('/leads/{lead}/custom-fields/{fieldKey}', [LeadCustomFieldController::class, 'destroyForLead']);

    /*
    |--------------------------------------------------------------------------
    | Settings Routes
    |--------------------------------------------------------------------------
    */
    Route::get('/settings-panel', [SettingsController::class, 'panel']);
    Route::put('/settings-panel', [SettingsController::class, 'update']);
    Route::post('/notification-settings', [NotificationSettingController::class, 'store']);
    Route::patch('/notification-settings', [NotificationSettingController::class, 'update']);

    /*
    |--------------------------------------------------------------------------
    | Internal Chat
    |--------------------------------------------------------------------------
    */

    Route::prefix('chat')->middleware('auth:sanctum')->group(function () {
        Route::get('conversations', [ConversationController::class, 'index']);
        Route::get('contacts', [ConversationController::class, 'contacts']);
        Route::post('private', [ConversationController::class, 'storePrivate']);
        Route::post('group', [ConversationController::class, 'storeGroup']);
        Route::delete('group/{conversation}', [ConversationController::class, 'destroyGroup']);
        Route::post('group/{conversation}/members', [ConversationController::class, 'addMembers']);
        Route::post('group/{conversation}/admins', [ConversationController::class, 'promoteAdmin']);
        Route::post('{conversation}/read', [ConversationController::class, 'markRead']);
        Route::get('{conversation}/messages', [InternalMessageController::class, 'index']);
        Route::post('messages', [InternalMessageController::class, 'store']);
        Route::delete('messages', [InternalMessageController::class, 'deleteMultiple']);
    });

    /*
    |--------------------------------------------------------------------------
    | Private File Serving
    |--------------------------------------------------------------------------
    */
    Route::get('/files/{path}', [FileController::class, 'serve'])->where('path', '.*');

    /*
    |--------------------------------------------------------------------------
    | Business Profile (the signed-in user's own company)
    |--------------------------------------------------------------------------
    | Tenant-facing. Distinct from the manager-key /manager/companies routes,
    | and never exposes plan, product module or the settings JSON.
    */
    /*
    |--------------------------------------------------------------------------
    | Contacts — every lead, pipeline membership optional
    |--------------------------------------------------------------------------
    */
    Route::get('/contacts', [ContactController::class, 'index']);

    /*
    |--------------------------------------------------------------------------
    | Optus — the in-app assistant. Conversations resolve from the
    | authenticated user, never from a client-supplied id.
    |--------------------------------------------------------------------------
    */
    Route::get('/optus/conversation', [OptusController::class, 'show']);
    Route::post('/optus/messages', [OptusController::class, 'send']);
    Route::delete('/optus/conversation', [OptusController::class, 'clear']);


    Route::get('/business-profile', [BusinessProfileController::class, 'show']);
    Route::put('/business-profile', [BusinessProfileController::class, 'update']);
    Route::post('/business-profile/logo', [BusinessProfileController::class, 'uploadLogo']);
    Route::delete('/business-profile/logo', [BusinessProfileController::class, 'deleteLogo']);
});

/*
|--------------------------------------------------------------------------
| Signed Public Media Serving
|--------------------------------------------------------------------------
| Outbound media sent to external platforms (e.g. Instagram's Graph API)
| must be fetched by that platform's own servers, which can't present our
| Sanctum auth header. This route is intentionally outside the auth:sanctum
| group and instead protected by Laravel's signed-URL middleware: only a
| URL generated by our backend (tied to one exact path, short-lived) is
| valid - it can't be used to browse arbitrary files.
|
| Uses a *relative* signature (ValidateSignature::relative()) rather than
| the absolute default - prod sits behind a reverse proxy that terminates
| TLS and forwards plain HTTP internally, so the scheme Laravel sees on the
| incoming request can't be trusted to match what was signed. A relative
| signature only covers the path + query string, so it's scheme/host
| agnostic and isn't affected by that mismatch.
*/
Route::get('/signed-media/{path}', [FileController::class, 'serveSigned'])
    ->where('path', '.*')
    ->name('files.signed')
    ->middleware(\Illuminate\Routing\Middleware\ValidateSignature::relative());


Route::middleware(['verify.api.token', 'log.api.requests'])->prefix('v1')->group(function () {
    Route::get('/protected', function(Request $request) {
        return response()->json([
            'message' => 'Access granted',
            'company' => $request->company
        ]);
    });

    /*
    |--------------------------------------------------------------------------
    | Leads
    |--------------------------------------------------------------------------
    */
    Route::get('/leads', [LeadController::class, 'apiIndex']);
    Route::post('/leads', [LeadController::class, 'apiStore']);
    Route::get('/leads/{id}', [LeadController::class, 'apiShow']);
    Route::put('/leads/{id}', [LeadController::class, 'apiUpdate']);
    Route::delete('/leads/{id}', [LeadController::class, 'apiDestroy']);

    /*
    |--------------------------------------------------------------------------
    | Sales / Deals
    |--------------------------------------------------------------------------
    */
    Route::get('/sales', [SaleController::class, 'apiIndex']);
    Route::post('/sales', [SaleController::class, 'apiStore']);
    Route::get('/sales/{id}', [SaleController::class, 'apiShow']);
    Route::put('/sales/{id}', [SaleController::class, 'apiUpdate']);
    Route::delete('/sales/{id}', [SaleController::class, 'apiDestroy']);

    /*
    |--------------------------------------------------------------------------
    | Products
    |--------------------------------------------------------------------------
    */
    Route::get('/products', [ProductController::class, 'apiIndex']);
    Route::post('/products', [ProductController::class, 'apiStore']);
    Route::get('/products/{id}', [ProductController::class, 'apiShow']);
    Route::put('/products/{id}', [ProductController::class, 'apiUpdate']);
    Route::delete('/products/{id}', [ProductController::class, 'apiDestroy']);
    Route::get('/product-categories', [ProductCategoryController::class, 'apiIndex']);


    /*
    |--------------------------------------------------------------------------
    | Users
    |--------------------------------------------------------------------------
    */
    Route::get('/users', [UserController::class, 'apiIndex']);
    Route::post('/users', [UserController::class, 'apiStore']);
    Route::get('/users/{id}', [UserController::class, 'apiShow']);
    Route::put('/users/{id}', [UserController::class, 'apiUpdate']);
    Route::delete('/users/{id}', [UserController::class, 'apiDestroy']);

    /*
    |--------------------------------------------------------------------------
    | Teams
    |--------------------------------------------------------------------------
    */
    Route::get('/teams', [TeamController::class, 'apiIndex']);
    Route::post('/teams', [TeamController::class, 'apiStore']);
    Route::get('/teams/{id}', [TeamController::class, 'apiShow']);
    Route::put('/teams/{id}', [TeamController::class, 'apiUpdate']);
    Route::delete('/teams/{id}', [TeamController::class, 'apiDestroy']);

    /*
    |--------------------------------------------------------------------------
    | Lead Companies
    |--------------------------------------------------------------------------
    */
    Route::get('/lead-companies', [LeadCompanyController::class, 'apiIndex']);
    Route::post('/lead-companies', [LeadCompanyController::class, 'apiStore']);
    Route::get('/lead-companies/{id}', [LeadCompanyController::class, 'apiShow']);
    Route::put('/lead-companies/{id}', [LeadCompanyController::class, 'apiUpdate']);
    Route::delete('/lead-companies/{id}', [LeadCompanyController::class, 'apiDestroy']);

    /*
    |--------------------------------------------------------------------------
    | Funnels
    |--------------------------------------------------------------------------
    */
    Route::get('/funnels', [FunnelController::class, 'apiIndex']);
    Route::post('/funnels', [FunnelController::class, 'apiStore']);
    Route::get('/funnels/{id}', [FunnelController::class, 'apiShow']);
    Route::put('/funnels/{id}', [FunnelController::class, 'apiUpdate']);
    Route::delete('/funnels/{id}', [FunnelController::class, 'apiDestroy']);

    /*
    |--------------------------------------------------------------------------
    | Stages (nested under funnels)
    |--------------------------------------------------------------------------
    */
    Route::get('/funnels/{funnelId}/stages', [StageController::class, 'apiIndex']);
    Route::post('/funnels/{funnelId}/stages', [StageController::class, 'apiStore']);
    Route::get('/funnels/{funnelId}/stages/{stageId}', [StageController::class, 'apiShow']);
    Route::put('/funnels/{funnelId}/stages/{stageId}', [StageController::class, 'apiUpdate']);
    Route::delete('/funnels/{funnelId}/stages/{stageId}', [StageController::class, 'apiDestroy']);

    Route::post('/conversation-reports', [ConversationReportController::class, 'store']);

    /*
    |--------------------------------------------------------------------------
    | Appointments
    |--------------------------------------------------------------------------
    */
    Route::get('/appointments', [AppointmentController::class, 'apiIndex']);
    Route::post('/appointments', [AppointmentController::class, 'apiStore']);
    Route::get('/appointments/{id}', [AppointmentController::class, 'apiShow']);
    Route::put('/appointments/{id}', [AppointmentController::class, 'apiUpdate']);
    Route::delete('/appointments/{id}', [AppointmentController::class, 'apiDestroy']);
    Route::post('/appointments/{id}/products/sync', [AppointmentController::class, 'apiSyncProducts']);

    /*
    |--------------------------------------------------------------------------
    | Lead + Appointment (combined, phone-deduplicated)
    |--------------------------------------------------------------------------
    */
    Route::post('/lead-appointments', [LeadAppointmentController::class, 'store']);

    /*
    |--------------------------------------------------------------------------
    | Lead Observations
    |--------------------------------------------------------------------------
    */
    Route::get('/leads/{leadId}/observations', [ObservationController::class, 'apiIndex']);
    Route::post('/leads/{leadId}/observations', [ObservationController::class, 'apiStore']);

    /*
    |--------------------------------------------------------------------------
    | Lead Tasks
    |--------------------------------------------------------------------------
    */
    Route::get('/tasks', [TaskController::class, 'apiIndex']);
    Route::post('/tasks', [TaskController::class, 'apiStore']);

    /*
    |--------------------------------------------------------------------------
    | Lead Products (Products of Interest)
    |--------------------------------------------------------------------------
    */
    Route::post('/leads/{leadId}/products', [LeadProductController::class, 'apiStore']);

    /*
    |--------------------------------------------------------------------------
    | Lead Move Stage
    |--------------------------------------------------------------------------
    */
    Route::post('/leads/{id}/move-stage', [LeadController::class, 'apiMoveStage']);

    /*
    |--------------------------------------------------------------------------
    | Files
    |--------------------------------------------------------------------------
    */
    Route::get('/files/{path}', [FileController::class, 'serveExternal'])->where('path', '.*');
});

// WhatsApp Evolution API Chat (webhook URL kept stable for Evolution API server config)
Route::post('/whatsapp-evo-chat', [InboxController::class, 'handleEvolutionWebhook']);

// Instagram Graph API webhook (called directly by Meta - no CRM session/JWT)
Route::get('/integrations/instagram/webhook/{webhookCallbackId}', [InstagramIntegrationController::class, 'verifyWebhook']);
Route::post('/integrations/instagram/webhook/{webhookCallbackId}', [InstagramIntegrationController::class, 'receiveWebhook']);

// Facebook Graph API webhook (called directly by Meta - no CRM session/JWT)
Route::get('/integrations/facebook/webhook/{webhookCallbackId}', [FacebookMessengerIntegrationController::class, 'verifyWebhook']);
Route::post('/integrations/facebook/webhook/{webhookCallbackId}', [FacebookMessengerIntegrationController::class, 'receiveWebhook']);

Broadcast::routes(['middleware' => ['auth:sanctum']]);
