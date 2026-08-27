<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('avisa que el correo no está configurado (MAIL_MAILER=array en tests)', function () {
    $this->postJson('/api/forgot-password', ['email' => 'quien@sea.com'])
        ->assertStatus(503)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'no está configurado para enviar correos'));
});

it('con un mailer real responde genérico y envía la notificación', function () {
    config(['mail.default' => 'smtp']);
    Notification::fake();

    $user = User::factory()->create(['email' => 'real@parroquia.com']);

    $this->postJson('/api/forgot-password', ['email' => 'real@parroquia.com'])
        ->assertOk()
        ->assertJsonStructure(['status']);

    Notification::assertSentTo($user, ResetPassword::class);
});

it('con un mailer real no revela si el email existe', function () {
    config(['mail.default' => 'smtp']);
    Notification::fake();

    $this->postJson('/api/forgot-password', ['email' => 'noexiste@parroquia.com'])
        ->assertOk()
        ->assertJsonStructure(['status']);

    Notification::assertNothingSent();
});
