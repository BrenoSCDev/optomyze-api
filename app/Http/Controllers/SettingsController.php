<?php

namespace App\Http\Controllers;

use App\Models\CustomProductField;
use App\Models\Funnel;
use App\Models\NotificationSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SettingsController extends Controller
{
    public function panel()
    {
        $user = Auth::user();

        $customProductFields = CustomProductField::where(['type' => 'model', 'company_id' => $user->company_id])->get();
        $funnels = Funnel::fromCompany($user->company_id)
            ->where('type', 'model')
            ->with('stages.entryRequirement')
            ->get();
        $notificationSettings = NotificationSetting::where('company_id', $user->company_id)->first();

        return response()->json([
            "customProductFields" => $customProductFields,
            "funnels" => $funnels,
            "notificationSettings" => $notificationSettings,
        ]);
    }
}
