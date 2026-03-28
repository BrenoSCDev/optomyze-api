<?php

namespace App\Http\Controllers;

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
        ]);
    }
}
