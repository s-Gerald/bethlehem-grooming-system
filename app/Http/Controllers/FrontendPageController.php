<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class FrontendPageController extends Controller
{
    public function show(Request $request)
    {
        return response()->file(base_path($request->route('file')), [
            'Cache-Control' => 'no-store, private, max-age=0, must-revalidate',
        ]);
    }

    public function redirect(Request $request)
    {
        $destination = $request->route('destination');
        // The old shared pet page used flow=clinic; its default was grooming.
        if ($request->is('pages/client/booking-pet-details.html') && $request->query('flow') !== 'clinic') {
            $destination = '/account/registration/grooming';
        }
        [$path, $fragment] = array_pad(explode('#', $destination, 2), 2, null);
        if ($query = $request->server('QUERY_STRING')) $path .= (str_contains($path, '?') ? '&' : '?').$query;

        return redirect($path.($fragment !== null ? '#'.$fragment : ''), 301);
    }
}
