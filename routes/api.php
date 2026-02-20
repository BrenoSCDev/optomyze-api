<?php

use App\Http\Controllers\ProductController;
use App\Http\Controllers\LeadDocumentController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\FunnelController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ConversationReportController;
use App\Http\Controllers\CustomProductFieldController;
use App\Http\Controllers\EntryRequirementController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\GoogleAdsIntegrationController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\LeadCompanyController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LeadTransactionController;
use App\Http\Controllers\MetaAdsIntegrationController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\N8nAgentController;
use App\Http\Controllers\N8nIntegrationController;
use App\Http\Controllers\ProductImageController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StageController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\LeadProductController;
use App\Http\Controllers\ObservationController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleDocController;
use App\Http\Controllers\SaleTransactionController;
use App\Http\Controllers\WhatsappEvoChatController;
use App\Http\Controllers\WhatsAppEvoIntegrationController;
use App\Http\Controllers\WhatsAppInstanceController;
use App\Http\Controllers\NotificationSettingController;
use App\Http\Controllers\OptomyzeErpIntegrationController;
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


Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/forgot-pwd', [AuthController::class, 'forgotPassword']);
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

Route::post('/companies', [CompanyController::class, 'store']);
Route::post('/users/manager', [UserController::class, 'storeManager']);


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


    /*
    |--------------------------------------------------------------------------
    | User Management Routes
    |--------------------------------------------------------------------------
    */
    Route::get('/users', [UserController::class, 'index']);
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
    | Funnels & Stages Routes
    |--------------------------------------------------------------------------
    */
    Route::get('/funnels', [FunnelController::class, 'index']);
    Route::get('/funnels/deals', [FunnelController::class, 'deals']);
    Route::post('/funnels', [FunnelController::class, 'store']);
    Route::get('/funnels/{id}', [FunnelController::class, 'leadsByStages']);
    Route::get('/funnels/{id}/deals', [FunnelController::class, 'dealsByStages']);
    Route::put('/funnels/{id}', [FunnelController::class, 'update']);
    Route::delete('/funnels/{id}', [FunnelController::class, 'destroy']);

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
    Route::post('/leads', [LeadController::class, 'store']);
    Route::get('/leads/search/{products?}', [LeadController::class, 'search']);
    Route::get('/leads/{lead}', [LeadController::class, 'show']);
    Route::put('/leads/{lead}', [LeadController::class, 'update']);
    Route::delete('/leads/{lead}', [LeadController::class, 'destroy']);
    Route::post('/leads/{lead}/move-stage', [LeadController::class, 'moveToStage']);
    Route::put('/leads/{id}/tags', [LeadController::class, 'updateTags']);
    Route::post('/leads/{lead}/assign', [LeadController::class, 'assignUser']);
    Route::post('leads/{lead}/add-erp-budget', [LeadController::class, 'addErpBudget']);
    Route::get('/leads-activity/transactions', [LeadController::class, 'leadTransactions']);
    Route::get('/leads-activity/tasks', [LeadController::class, 'leadTasks']);

    Route::post('/leads/{lead}/products', [LeadProductController::class, 'store']);
    Route::post('/leads/{lead}/sales', [SaleController::class, 'store']);
    Route::delete('/sales/{sale}', [SaleController::class, 'destroy']);

    Route::post('/sales/{sale}/docs', [SaleDocController::class, 'store']);
    Route::delete('/sales/docs/{doc}', [SaleDocController::class, 'destroy']);
    Route::get('/sales/{sale}', [SaleController::class, 'show']);
    Route::put('/sales/{sale}/status', [SaleController::class, 'updateStatus']);
    Route::patch('/sales/{sale}/move-stage', [SaleController::class, 'moveToStage']);
    Route::get('/deals-activity/transactions', [SaleController::class, 'dealTransactions']);

    /*
    |--------------------------------------------------------------------------
    | Observations (Lead Notes)
    |--------------------------------------------------------------------------
    */
    Route::get('/leads/{lead}/observations', [ObservationController::class, 'index']);
    Route::post('/observations', [ObservationController::class, 'store']);
    Route::put('/observations/{observation}', [ObservationController::class, 'update']);
    Route::delete('/observations/{observation}', [ObservationController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | Tags & Tokens Routes
    |--------------------------------------------------------------------------
    */
    Route::get('/tags', [TagController::class, 'index']);
    Route::post('/tags', [TagController::class, 'store']);
    Route::delete('/tags/{id}', [TagController::class, 'destroy']);

    Route::get('/tokens', [ApiTokenController::class, 'index']);
    Route::post('/tokens', [ApiTokenController::class, 'generate']);
    Route::delete('/tokens/{id}', [ApiTokenController::class, 'destroy']);


    /*
    |--------------------------------------------------------------------------
    | Transactions & Metrics Routes
    |--------------------------------------------------------------------------
    */
    Route::get('/companies/{companyId}/transactions', [LeadTransactionController::class, 'transactionsByCompany']);
    Route::get('/leads/{leadId}/transactions', [LeadTransactionController::class, 'transactionsByLead']);

    Route::get('/sales/{saleId}/transactions', [SaleTransactionController::class, 'transactionsBySale']);
    Route::get('/companies/{companyId}/sale-transactions', [SaleTransactionController::class, 'transactionsByCompany']);

    Route::get('/metrics/leads-breakdown', [MetricsController::class, 'dashboard']);
    Route::get('/metrics/monthly-charts', [MetricsController::class, 'monthlyCharts']);
    Route::get('/metrics/plan-usage', [MetricsController::class, 'planUsage']);


    /*
    |--------------------------------------------------------------------------
    | Integrations Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('integrations')->group(function () {

        // Available integrations
        Route::get('/available', [IntegrationController::class, 'available']);
        Route::get('/available/settings', [IntegrationController::class, 'availableWithSettings']);

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
        Route::post('/whatsapp-evo-instances', [WhatsAppInstanceController::class, 'createInstance']);
        Route::delete('/whatsapp-evo-instances/{instanceId}', [WhatsAppInstanceController::class, 'deleteInstance']);
        Route::delete('/whatsapp-evo-instances/{instanceId}', [WhatsAppInstanceController::class, 'deleteInstance']);
        Route::get('/whatsapp-evo-instances/qrcode/{instanceId}', [WhatsAppInstanceController::class, 'generateQrCode']);
        Route::post('/whatsapp-evo-instances/send-message/{instanceId}', [WhatsAppInstanceController::class, 'sendMessage']);
        Route::put('/whatsapp-evo-integrations/{instance}/funnel', [WhatsAppInstanceController::class, 'updateFunnel']);

        // WhatsApp Evolution API Chat
        Route::get('/whatsapp-evo-chat', [WhatsappEvoChatController::class, 'getCompanyChats']);
        Route::post('/whatsapp-evo-chat/message/{instanceId}', [WhatsappEvoChatController::class, 'sendMessageToLead']);
        
    });


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
    Route::get('/tasks', [TaskController::class, 'companyTasks']);
    Route::post('/tasks', [TaskController::class, 'store']);
    Route::get('/tasks/{task}', [TaskController::class, 'show']);
    Route::put('/tasks/{task}', [TaskController::class, 'update']);
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy']);


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
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);
    Route::post('/products/{id}/toggle-active', [ProductController::class, 'toggleActive']);

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

    /*
    |--------------------------------------------------------------------------
    | Settings Routes
    |--------------------------------------------------------------------------
    */
    Route::get('/settings-panel', [SettingsController::class, 'panel']);
    Route::post('/notification-settings', [NotificationSettingController::class, 'store']);
    Route::patch('/notification-settings', [NotificationSettingController::class, 'update']);

    /*
    |--------------------------------------------------------------------------
    | Calendar Events Routes
    |--------------------------------------------------------------------------
    */
    Route::get('/events', [EventController::class, 'index']);
    Route::get('/events/{event}', [EventController::class, 'show']);
    Route::post('/events', [EventController::class, 'store']);
    Route::put('/events/{event}', [EventController::class, 'update']);
    Route::delete('/events/{event}', [EventController::class, 'destroy']);
});


Route::middleware('verify.api.token')->prefix('v1')->group(function () {
    Route::get('/protected', function(Request $request) {
        return response()->json([
            'message' => 'Access granted',
            'company' => $request->company
        ]);
    });

    Route::post('/leads', [LeadController::class, 'apiStore']);
    
    Route::post('/conversation-reports', [ConversationReportController::class, 'store']);
});

// WhatsApp Evolution API Chat
Route::post('/whatsapp-evo-chat', [WhatsappEvoChatController::class, 'handleEvolutionWebhook']);

Broadcast::routes(['middleware' => ['auth:sanctum']]);
