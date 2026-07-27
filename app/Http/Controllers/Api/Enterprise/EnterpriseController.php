<?php

namespace App\Http\Controllers\Api\Enterprise;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;

abstract class EnterpriseController extends Controller
{
    protected function company(Request $request): Company
    {
        $company = $request->user()?->company;

        abort_unless($company, 403, 'Aucune entreprise n’est associée à ce compte.');

        return $company;
    }
}
