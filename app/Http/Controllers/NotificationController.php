<?php

namespace App\Http\Controllers;

class NotificationController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        return view('notifications.index', ['items' => $user->notifications()->latest()->limit(100)->get()]);
    }

    public function read(string $id)
    {
        $n = auth()->user()->notifications()->findOrFail($id);
        $n->markAsRead();

        $url = (string) ($n->data['url'] ?? '');
        $sameHost = $url !== '' && (str_starts_with($url, '/') && !str_starts_with($url, '//')
            || parse_url($url, PHP_URL_HOST) === parse_url(config('app.url'), PHP_URL_HOST)
            || parse_url($url, PHP_URL_HOST) === request()->getHost());

        return redirect($sameHost ? $url : route('notifications.index'));
    }

    public function readAll()
    {
        auth()->user()->unreadNotifications->markAsRead();

        return back();
    }
}
