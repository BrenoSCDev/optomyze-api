<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE DOCUMENT MODEL BECOMES STRUCTURED.
 *
 * Until now a template *was* its HTML: users typed markup into a textarea and
 * that string was the document. That makes HTML the model, which locks the
 * platform to one output format and makes non-technical authoring impossible.
 *
 * From here the model is a ProseMirror/TipTap document tree in `body_doc`, and
 * HTML is something we *generate* from it:
 *
 *     body_doc (JSON)  →  body_html  →  PDF  →  signature provider
 *      source of truth     output       output
 *
 * `body_html` is kept, still populated, still what the renderer reads. That is
 * deliberate and load-bearing:
 *
 *   - Every existing template keeps working untouched. A row with body_html
 *     and a null body_doc renders exactly as it did yesterday.
 *   - The generation pipeline, the PDF renderer, document_versions and the
 *     whole signature flow need no changes at all.
 *   - Serialisation stays in one place (the editor, which owns the schema)
 *     rather than being reimplemented in PHP where it would drift.
 *
 * So this migration is additive only. Nothing is dropped, nothing is
 * rewritten, and a rollback loses the visual authoring but not a single
 * document.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            /*
             * The editor's document tree. Null for templates authored before
             * the visual editor existed — DocumentTemplate::hasStructuredBody()
             * is how the UI decides whether to offer conversion.
             */
            $table->json('body_doc')->nullable()->after('body_html');
            $table->json('header_doc')->nullable()->after('header_html');
            $table->json('footer_doc')->nullable()->after('footer_html');

            /*
             * Paper, orientation, margins, default typography.
             *
             * One JSON column rather than a dozen scalars because these are
             * read and written as a single unit by both the editor canvas and
             * the PDF renderer, and because the set will grow (per-section
             * margins, first-page-different headers) without wanting a
             * migration each time. Nothing joins or filters on them.
             */
            $table->json('page_settings')->nullable()->after('variables_schema');

            /*
             * Which editor schema version wrote body_doc.
             *
             * Node types get added and renamed. Without this, a document
             * written by a later editor and opened by an earlier one fails in
             * whatever way ProseMirror happens to fail, rather than being
             * refused with an explanation.
             */
            $table->unsignedSmallInteger('doc_schema_version')->nullable()->after('body_doc');
        });

        /*
         * Template revision history.
         *
         * Generated documents already snapshot their body, so contracts were
         * never at risk from an edit. The gap is the *author's*: there was no
         * way back from "I have just rewritten the standard services
         * agreement and it was worse". This is that undo, and it is why a
         * revision stores the whole body rather than a diff — restoring must
         * not depend on replaying a chain.
         */
        Schema::create('document_template_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_template_id')
                ->constrained('document_templates')->cascadeOnDelete();

            $table->unsignedInteger('revision_number');

            $table->json('body_doc')->nullable();
            $table->longText('body_html')->nullable();
            $table->json('header_doc')->nullable();
            $table->text('header_html')->nullable();
            $table->json('footer_doc')->nullable();
            $table->text('footer_html')->nullable();
            $table->json('page_settings')->nullable();

            // Autosaves are frequent and uninteresting; explicit saves and
            // publishes are the ones a human looks for in the list.
            $table->string('trigger', 20)->default('autosave');
            $table->string('label')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Named explicitly: the conventional name Laravel would derive
            // here is 66 characters, past MySQL's 64-character identifier
            // limit, and the migration fails on it.
            $table->unique(['document_template_id', 'revision_number'], 'dtr_template_revision_unique');
            $table->index(['document_template_id', 'created_at'], 'dtr_template_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_template_revisions');

        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn([
                'body_doc',
                'header_doc',
                'footer_doc',
                'page_settings',
                'doc_schema_version',
            ]);
        });
    }
};
