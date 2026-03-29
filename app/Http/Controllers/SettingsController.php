<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CustomProductField;
use App\Models\Funnel;
use App\Models\LeadCustomField;
use App\Models\FunnelManagerQueue;
use App\Models\LostReason;
use App\Models\NotificationSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SettingsController extends Controller
{
    public function panel()
    {
        $user = Auth::user();

        $customProductFields = CustomProductField::where(['type' => 'model', 'company_id' => $user->company_id])->get();
        $customLeadFields    = LeadCustomField::where(['type' => 'model', 'company_id' => $user->company_id])->get();
        $funnels = Funnel::fromCompany($user->company_id)
            ->where('type', 'sale_model')
            ->with('stages.entryRequirement')
            ->get();
        $notificationSettings = NotificationSetting::where('company_id', $user->company_id)->first();
        $company = Company::find($user->company_id);
        $companySettings = $company->settings ?? [];

        $queues = FunnelManagerQueue::fromCompany($user->company_id)
            ->with([
                'users:id,name,email',
                'funnels:id,funnel_manager_queue_id,name,type,is_active',
            ])
            ->get();

        $lostReasons = LostReason::fromCompany($user->company_id)
            ->latest()
            ->get();

        return response()->json([
            "customProductFields"  => $customProductFields,
            "customLeadFields"     => $customLeadFields,
            "funnels"              => $funnels,
            "notificationSettings" => $notificationSettings,
            "queues"               => $queues,
            "lostReasons"          => $lostReasons,
            "product_settings"     => $companySettings['product_settings'] ?? null,
            "sales_settings"       => $companySettings['sales_settings'] ?? null,
        ]);
    }

    public function update(Request $request)
    {
        $user = Auth::user();
        $company = Company::find($user->company_id);

        $validated = $request->validate([
            'product_settings'                                            => 'sometimes|array',
            'product_settings.default_type'                               => 'sometimes|string|in:service,physical,digital',
            'product_settings.lock_type'                                  => 'sometimes|boolean',
            'product_settings.default_pricing_model'                      => 'sometimes|string',
            'product_settings.default_currency'                           => 'sometimes|string|size:3',
            'product_settings.auto_sku'                                   => 'sometimes|boolean',
            'product_settings.sku_format'                                 => 'sometimes|string|max:100',
            'product_settings.default_status'                             => 'sometimes|string|in:active,draft',
            'product_settings.pricing_rules'                              => 'sometimes|array',
            'product_settings.pricing_rules.allowPriceOverride'           => 'sometimes|boolean',
            'product_settings.pricing_rules.maxDiscountPercent'           => 'sometimes|numeric|min:0|max:100',
            'product_settings.pricing_rules.defaultDiscountType'          => 'sometimes|string|in:percentage,fixed',
            'product_settings.pricing_rules.minDealValue'                 => 'sometimes|numeric|min:0',
            'sales_settings'                                              => 'sometimes|array',
            'sales_settings.deal_rules'                                   => 'sometimes|array',
            'sales_settings.deal_rules.allowDealsWithoutProducts'         => 'sometimes|boolean',
            'sales_settings.deal_rules.requireProductForProposal'         => 'sometimes|boolean',
            'sales_settings.deal_rules.requirePricingForNegotiation'      => 'sometimes|boolean',
            'sales_settings.deal_rules.lockProductsAfterWon'              => 'sometimes|boolean',
            'sales_settings.deal_defaults'                                => 'sometimes|array',
            'sales_settings.commissions'                                  => 'sometimes|array',
            'sales_settings.notifications'                                => 'sometimes|array',
            'sales_settings.automations'                                  => 'sometimes|array',
            'sales_settings.permissions'                                  => 'sometimes|array',
        ]);

        $currentSettings = $company->settings ?? [];

        if (isset($validated['product_settings'])) {
            $currentSettings['product_settings'] = array_replace_recursive(
                $currentSettings['product_settings'] ?? [],
                $validated['product_settings']
            );
        }

        if (isset($validated['sales_settings'])) {
            $currentSettings['sales_settings'] = array_replace_recursive(
                $currentSettings['sales_settings'] ?? [],
                $validated['sales_settings']
            );
        }

        $company->settings = $currentSettings;
        $company->save();

        return response()->json([
            'product_settings' => $currentSettings['product_settings'] ?? null,
            'sales_settings'   => $currentSettings['sales_settings'] ?? null,
        ]);
    }
}
