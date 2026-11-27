<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
        });

        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone')->default('America/Vancouver');
            $table->string('currency', 3)->default('CAD');
            $table->string('locale_primary')->default('en');
            $table->string('locale_secondary')->default('pa-Latn');
            $table->time('reminder_local_time')->default('09:00:00');
            $table->string('payment_instructions')->default('Pay by cash or e-transfer, then reply I paid so the kitchen can verify it.');
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->unsignedInteger('lead_time_minutes')->default(20);
            $table->boolean('ordering_paused')->default(false);
            $table->timestamps();
        });

        Schema::create('business_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->unique(['location_id', 'day_of_week']);
        });

        Schema::create('merchant_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('module');
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->unique(['merchant_id', 'module']);
        });

        Schema::create('cutoff_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->time('cutoff_local_time');
            $table->unsignedSmallInteger('grace_minutes')->default(10);
            $table->timestamps();
            $table->unique('merchant_id');
        });

        Schema::create('merchant_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();
            $table->unique(['merchant_id', 'user_id']);
        });

        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('phone_number_id')->unique();
            $table->string('waba_id')->nullable();
            $table->string('display_number');
            $table->text('access_token')->nullable();
            $table->string('status')->default('pending_production');
            $table->string('existing_number_status')->default('unchecked');
            $table->timestamp('production_access_confirmed_at')->nullable();
            $table->string('google_chat_status')->default('unknown');
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('phone_e164');
            $table->string('name')->nullable();
            $table->string('address')->nullable();
            $table->timestamp('address_approved_at')->nullable();
            $table->foreignId('address_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('language')->default('en');
            $table->string('clover_customer_id')->nullable();
            $table->timestamps();
            $table->unique(['merchant_id', 'phone_e164']);
        });

        Schema::create('notification_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->boolean('proactive_allowed')->default(false);
            $table->timestamp('opted_out_at')->nullable();
            $table->string('source')->default('unknown');
            $table->timestamps();
            $table->unique('customer_id');
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_account_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('automated');
            $table->foreignId('taken_over_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('taken_over_at')->nullable();
            $table->timestamp('last_customer_message_at')->nullable();
            $table->timestamps();
            $table->unique(['merchant_id', 'customer_id']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('payload_hash')->index();
            $table->string('phone_number_id')->nullable()->index();
            $table->json('raw_payload');
            $table->boolean('signature_valid')->default(false);
            $table->string('resolution')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('inbound_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_message_id')->unique();
            $table->dateTime('wa_timestamp');
            $table->string('message_type');
            $table->text('body')->nullable();
            $table->json('raw')->nullable();
            $table->string('draft')->nullable();
            $table->string('result')->nullable();
            $table->string('processing_status')->default('received');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('proposed_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbound_message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('module');
            $table->string('action_type');
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('supersedes_id')->nullable()->index();
            $table->string('status');
            $table->json('payload');
            $table->text('summary');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('outbound_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('proposed_action_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->string('template_name')->nullable();
            $table->text('body');
            $table->boolean('is_proactive')->default(false);
            $table->string('source')->default('automation');
            $table->string('status')->default('queued');
            $table->string('provider_message_id')->nullable();
            $table->string('failure_reason')->nullable();
            $table->string('idempotency_key')->unique();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('staff_handoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbound_message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('proposed_action_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason');
            $table->text('draft_interpretation')->nullable();
            $table->string('status')->default('open');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('source');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('integration_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('integration');
            $table->string('severity')->default('warning');
            $table->text('message');
            $table->string('status')->default('open');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('plan_kind');
            $table->json('service_days');
            $table->unsignedSmallInteger('lunch_portions')->default(0);
            $table->unsignedSmallInteger('dinner_portions')->default(0);
            $table->string('meal_type')->default('regular');
            $table->unsignedInteger('plan_price_cents');
            $table->unsignedSmallInteger('pack_size')->default(0);
            $table->unsignedInteger('pack_sequence')->default(1);
            $table->unsignedSmallInteger('cycle_days')->nullable();
            $table->date('start_date');
            $table->date('period_end')->nullable();
            $table->integer('portion_credits_remaining')->default(0);
            $table->unsignedSmallInteger('renewal_portion_threshold')->nullable();
            $table->unsignedSmallInteger('renewal_days_threshold')->nullable();
            $table->string('address')->nullable();
            $table->text('packing_notes')->nullable();
            $table->text('terms_summary');
            $table->timestamp('customer_confirmed_at')->nullable();
            $table->timestamp('owner_approved_at')->nullable();
            $table->foreignId('owner_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('pause_open_ended')->default(false);
            $table->date('paused_until')->nullable();
            $table->timestamps();
        });

        Schema::create('schedule_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->date('service_date');
            $table->string('slot');
            $table->unsignedSmallInteger('planned_quantity');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedSmallInteger('skipped_quantity')->default(0);
            $table->string('meal_type');
            $table->string('status');
            $table->string('credit_treatment')->nullable();
            $table->foreignId('proposed_action_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('authorized_late_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fulfilled_at')->nullable();
            $table->string('fulfillment_key')->nullable()->unique();
            $table->timestamps();
            $table->unique(['subscription_id', 'service_date', 'slot']);
        });

        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cycle_key');
            $table->string('kind');
            $table->date('issue_date');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date');
            $table->unsignedInteger('amount_cents');
            $table->string('status')->default('issued');
            $table->timestamps();
            $table->unique(['subscription_id', 'cycle_key']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->date('received_date');
            $table->string('method');
            $table->integer('amount_cents');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('reversal_of_id')->nullable()->index();
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('open');
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->date('due_on');
            $table->string('status')->default('pending');
            $table->foreignId('outbound_notification_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['bill_id', 'due_on']);
        });

        Schema::create('renewal_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('cycle_key');
            $table->string('status');
            $table->date('last_covered_date')->nullable();
            $table->foreignId('outbound_notification_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bill_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['subscription_id', 'cycle_key']);
        });

        Schema::create('clover_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('clover_merchant_id');
            $table->text('access_token')->nullable();
            $table->string('environment')->default('sandbox');
            $table->string('pickup_order_type_id')->nullable();
            $table->string('default_printer_id')->nullable();
            $table->string('print_mode')->default('explicit_print');
            $table->boolean('ordering_paused')->default(false);
            $table->boolean('can_create_customers')->default(false);
            $table->timestamp('catalog_synced_at')->nullable();
            $table->unsignedSmallInteger('sync_max_age_minutes')->default(360);
            $table->timestamps();
            $table->unique('merchant_id');
        });

        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clover_connection_id')->constrained()->cascadeOnDelete();
            $table->string('clover_item_id');
            $table->string('name');
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('tax_rate_bps')->default(500);
            $table->boolean('available')->default(true);
            $table->json('modifier_groups')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['clover_connection_id', 'clover_item_id']);
        });

        Schema::create('pickup_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proposed_action_id')->nullable()->constrained()->nullOnDelete();
            $table->string('idempotency_key')->unique();
            $table->string('submission_status')->default('pending');
            $table->string('dispatch_status')->default('pending');
            $table->string('pickup_status')->default('pending');
            $table->string('clover_order_id')->nullable()->unique();
            $table->unsignedInteger('quoted_subtotal_cents')->default(0);
            $table->unsignedInteger('quoted_tax_cents')->default(0);
            $table->unsignedInteger('quoted_total_cents')->default(0);
            $table->string('pickup_name');
            $table->dateTime('pickup_at');
            $table->boolean('pay_at_pickup')->default(true);
            $table->json('submitted_payload')->nullable();
            $table->timestamp('catalog_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pickup_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pickup_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('clover_item_id');
            $table->string('name');
            $table->unsignedSmallInteger('quantity');
            $table->json('modifiers')->nullable();
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedInteger('line_total_cents');
            $table->timestamps();
        });

        Schema::create('print_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pickup_order_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('clover_print_event_id')->nullable();
            $table->string('status');
            $table->boolean('marked_copy')->default(false);
            $table->json('ticket');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_events');
        Schema::dropIfExists('pickup_order_lines');
        Schema::dropIfExists('pickup_orders');
        Schema::dropIfExists('catalog_items');
        Schema::dropIfExists('clover_connections');
        Schema::dropIfExists('renewal_prompts');
        Schema::dropIfExists('payment_reminders');
        Schema::dropIfExists('payment_tasks');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('bills');
        Schema::dropIfExists('schedule_entries');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('integration_alerts');
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('staff_handoffs');
        Schema::dropIfExists('outbound_notifications');
        Schema::dropIfExists('proposed_actions');
        Schema::dropIfExists('inbound_messages');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('notification_permissions');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('whatsapp_accounts');
        Schema::dropIfExists('merchant_user');
        Schema::dropIfExists('cutoff_policies');
        Schema::dropIfExists('merchant_modules');
        Schema::dropIfExists('business_hours');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('merchants');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
