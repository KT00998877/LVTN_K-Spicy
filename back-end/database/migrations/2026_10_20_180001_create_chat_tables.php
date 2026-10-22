<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * chat_sessions and chat_messages already exist in this database, created by
 * migrations 2026_10_18_000001/000002 (batch 3) with live conversation rows.
 *
 * Those migration files are no longer present in the repository, so Laravel can
 * no longer discover them. This migration records them as applied WITHOUT
 * touching the tables — recreating them would fail (table exists) and dropping
 * them would destroy 148 real chat rows.
 *
 * chat_sessions.guest_token is therefore NOT created here: the live table
 * already has that column (varchar(64), nullable, indexed).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['2026_10_18_000001_create_chat_sessions_table',
                  '2026_10_18_000002_create_chat_messages_table'] as $migration) {
            if (!DB::table('migrations')->where('migration', $migration)->exists()) {
                DB::table('migrations')->insert([
                    'migration' => $migration,
                    'batch' => 0,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Recorded-only migration: nothing to reverse.
    }
};