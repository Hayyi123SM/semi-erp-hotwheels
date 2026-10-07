<?php

namespace Database\Factories;

use App\Enums\NotificationStatus;
use App\Models\Consignment;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            /**
             * Diturunkan dari `consignment_id` yang sudah selesai dibuat,
             * supaya kunci factory sama dengan yang dihitung
             * `NotificationSender::keyFor()`. Kunci yang tidak cocok bentuknya
             * membuat test idempotensi lulus karena tidak pernah menguji
             * benturan yang sebenarnya terjadi.
             */
            'notification_key' => fn (array $attributes): string => 'consignment_receipt:'.$attributes['consignment_id'],
            'channel' => 'WHATSAPP',
            'template_name' => 'consignment_receipt',
            'recipient' => '6281234567890',
            'status' => NotificationStatus::Pending->value,
            'attempts' => 0,
            'consignment_id' => Consignment::factory(),
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => NotificationStatus::Sent->value,
            'attempts' => 1,
            'sent_at' => now(),
        ]);
    }

    public function failed(string $reason = 'Nomor WhatsApp tidak valid.'): static
    {
        return $this->state(fn (): array => [
            'status' => NotificationStatus::Failed->value,
            'attempts' => 1,
            'failed_at' => now(),
            'error_message' => $reason,
        ]);
    }
}
