<?php

namespace App\Console\Commands;

use App\Models\InboxChat;
use App\Models\WhatsAppInstance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateWhatsAppToInboxCommand extends Command
{
    protected $signature = 'inbox:migrate-whatsapp {--dry-run : Preview the migration without writing any data}';

    protected $description = 'Migrate whatsapp_evo_chats/whatsapp_evo_messages into the generic inbox_chats/inbox_messages tables';

    public function handle(): int
    {
        $chatCount = DB::table('whatsapp_evo_chats')->count();
        $messageCount = DB::table('whatsapp_evo_messages')->count();

        $this->info("Source: {$chatCount} whatsapp_evo_chats, {$messageCount} whatsapp_evo_messages.");

        if ($this->option('dry-run')) {
            $this->info('Dry run - no data will be written.');
            return self::SUCCESS;
        }

        DB::transaction(function () {
            $this->migrateChats();
            $this->migrateMessages();
        });

        $this->resetAutoIncrement('inbox_chats');
        $this->resetAutoIncrement('inbox_messages');

        $newChatCount = DB::table('inbox_chats')->count();
        $newMessageCount = DB::table('inbox_messages')->count();

        $this->info("Destination: {$newChatCount} inbox_chats, {$newMessageCount} inbox_messages.");

        return self::SUCCESS;
    }

    private function migrateChats(): void
    {
        DB::table('whatsapp_evo_chats')->orderBy('id')->chunkById(500, function ($chats) {
            $rows = $chats->map(fn ($chat) => [
                'id' => $chat->id,
                'company_id' => $chat->company_id,
                'channel' => InboxChat::CHANNEL_WHATSAPP,
                'channelable_type' => WhatsAppInstance::class,
                'channelable_id' => $chat->whatsapp_instance_id,
                'lead_id' => $chat->lead_id,
                'external_chat_id' => $chat->remote_jid,
                'external_contact_id' => $chat->phone_number,
                'contact_name' => null,
                'is_group' => $chat->is_group,
                'is_archived' => $chat->is_archived,
                'last_message_at' => $chat->last_message_at,
                'profile_pic_url' => $chat->profile_pic_url,
                'created_at' => $chat->created_at,
                'updated_at' => $chat->updated_at,
            ])->all();

            DB::table('inbox_chats')->upsert($rows, ['id'], [
                'company_id', 'channel', 'channelable_type', 'channelable_id', 'lead_id',
                'external_chat_id', 'external_contact_id', 'contact_name', 'is_group',
                'is_archived', 'last_message_at', 'profile_pic_url', 'created_at', 'updated_at',
            ]);
        });
    }

    private function migrateMessages(): void
    {
        DB::table('whatsapp_evo_messages')->orderBy('id')->chunkById(500, function ($messages) {
            $rows = $messages->map(fn ($message) => [
                'id' => $message->id,
                'inbox_chat_id' => $message->whatsapp_evo_chat_id,
                'user_id' => $message->user_id,
                'external_message_id' => $message->wa_message_id,
                'direction' => $message->direction,
                'message_mode' => $message->message_mode,
                'type' => $message->type,
                'text' => $message->text,
                'media_url' => $message->media_url,
                'media_mime' => $message->media_mime,
                'media_size' => $message->media_size,
                'latitude' => $message->latitude,
                'longitude' => $message->longitude,
                'metadata' => $message->metadata,
                'status' => $message->status,
                'sent_at' => $message->sent_at,
                'created_at' => $message->created_at,
                'updated_at' => $message->updated_at,
            ])->all();

            DB::table('inbox_messages')->upsert($rows, ['id'], [
                'inbox_chat_id', 'user_id', 'external_message_id', 'direction', 'message_mode',
                'type', 'text', 'media_url', 'media_mime', 'media_size', 'latitude', 'longitude',
                'metadata', 'status', 'sent_at', 'created_at', 'updated_at',
            ]);
        });
    }

    private function resetAutoIncrement(string $table): void
    {
        $maxId = (int) DB::table($table)->max('id');

        match (DB::connection()->getDriverName()) {
            'mysql' => DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = " . ($maxId + 1)),
            'pgsql' => DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), {$maxId}, true)"),
            'sqlite' => DB::table('sqlite_sequence')->updateOrInsert(['name' => $table], ['seq' => $maxId]),
            default => null,
        };
    }
}
