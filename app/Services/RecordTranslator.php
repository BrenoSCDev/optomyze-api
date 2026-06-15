<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Centralized translation of Record titles/descriptions.
 *
 * "Hybrid" i18n: titles/descriptions are rendered ONCE at creation time in
 * the company's configured language (EN/PT/ES/DE) and persisted on the
 * Record row. The structured `type`/`action`/`metadata` are also persisted
 * so a Record can be re-rendered later (e.g. if the company changes its
 * language, or for future locales) without losing information.
 */
class RecordTranslator
{
    public const LANGUAGES = ['EN', 'PT', 'ES', 'DE'];

    /**
     * Render a title + description for a Record.
     *
     * @param string $language       Company language (EN/PT/ES/DE)
     * @param string $recordableType 'Lead' or 'Sale'
     * @param string $type           Record type (e.g. 'stage_change')
     * @param string $action         Record action (e.g. 'moved')
     * @param string|null $variant   Optional variant suffix for description lookup
     * @param array $params          Placeholders for interpolation (e.g. ['leadName' => 'John Doe'])
     */
    public static function translate(
        string $language,
        string $recordableType,
        string $type,
        string $action,
        ?string $variant = null,
        array $params = []
    ): array {
        $language = in_array($language, self::LANGUAGES, true) ? $language : 'EN';

        $titleKey = "{$recordableType}.{$type}.{$action}";
        $descriptionKey = $variant ? "{$titleKey}.{$variant}" : $titleKey;

        $titles = self::titles();
        $descriptions = self::descriptions();

        $title = $titles[$titleKey][$language]
            ?? $titles[$titleKey]['EN']
            ?? self::humanize($type, $action);

        $descriptionTemplate = $descriptions[$descriptionKey][$language]
            ?? $descriptions[$descriptionKey]['EN']
            ?? ($params['fallback'] ?? '');

        return [
            'title' => $title,
            'description' => self::interpolate($descriptionTemplate, $params),
        ];
    }

    /**
     * Resolve just the title for a given type/action, e.g. for data
     * migrations re-rendering legacy rows that lack structured metadata.
     */
    public static function titleFor(string $language, string $recordableType, string $type, string $action): string
    {
        $language = in_array($language, self::LANGUAGES, true) ? $language : 'EN';
        $key = "{$recordableType}.{$type}.{$action}";
        $titles = self::titles();

        return $titles[$key][$language] ?? $titles[$key]['EN'] ?? self::humanize($type, $action);
    }

    private static function interpolate(string $template, array $params): string
    {
        $replacements = [];
        foreach ($params as $key => $value) {
            $replacements['{' . $key . '}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }

    private static function humanize(string $type, string $action): string
    {
        return Str::title(str_replace('_', ' ', $type)) . ' — ' . Str::title($action);
    }

    /**
     * Short headlines, keyed by "{recordableType}.{type}.{action}".
     */
    private static function titles(): array
    {
        return [
            'Lead.creation.created' => [
                'EN' => 'Lead Created',
                'PT' => 'Lead Criado',
                'ES' => 'Lead Creado',
                'DE' => 'Lead Erstellt',
            ],
            'Lead.whatsapp_chat.chat_started' => [
                'EN' => 'WhatsApp Chat Started',
                'PT' => 'Conversa do WhatsApp Iniciada',
                'ES' => 'Chat de WhatsApp Iniciado',
                'DE' => 'WhatsApp-Chat Gestartet',
            ],
            'Lead.instagram_chat.chat_started' => [
                'EN' => 'Instagram Chat Started',
                'PT' => 'Conversa do Instagram Iniciada',
                'ES' => 'Chat de Instagram Iniciado',
                'DE' => 'Instagram-Chat Gestartet',
            ],
            'Lead.lost.lost' => [
                'EN' => 'Lead Marked as Lost',
                'PT' => 'Lead Marcado como Perdido',
                'ES' => 'Lead Marcado como Perdido',
                'DE' => 'Lead als Verloren Markiert',
            ],
            'Lead.stage_change.moved' => [
                'EN' => 'Stage Changed',
                'PT' => 'Etapa Alterada',
                'ES' => 'Etapa Cambiada',
                'DE' => 'Phase Geändert',
            ],
            'Sale.creation.created' => [
                'EN' => 'Deal Created',
                'PT' => 'Negócio Criado',
                'ES' => 'Negocio Creado',
                'DE' => 'Deal Erstellt',
            ],
            'Sale.deletion.deleted' => [
                'EN' => 'Deal Deleted',
                'PT' => 'Negócio Excluído',
                'ES' => 'Negocio Eliminado',
                'DE' => 'Deal Gelöscht',
            ],
            'Sale.stage_change.won' => [
                'EN' => 'Deal Won',
                'PT' => 'Negócio Ganho',
                'ES' => 'Negocio Ganado',
                'DE' => 'Deal Gewonnen',
            ],
            'Sale.stage_change.lost' => [
                'EN' => 'Deal Lost',
                'PT' => 'Negócio Perdido',
                'ES' => 'Negocio Perdido',
                'DE' => 'Deal Verloren',
            ],
            'Sale.stage_change.moved' => [
                'EN' => 'Stage Changed',
                'PT' => 'Etapa Alterada',
                'ES' => 'Etapa Cambiada',
                'DE' => 'Phase Geändert',
            ],
            'Sale.status_change.closed' => [
                'EN' => 'Deal Closed',
                'PT' => 'Negócio Fechado',
                'ES' => 'Negocio Cerrado',
                'DE' => 'Deal Abgeschlossen',
            ],
            'Sale.status_change.lost' => [
                'EN' => 'Deal Lost',
                'PT' => 'Negócio Perdido',
                'ES' => 'Negocio Perdido',
                'DE' => 'Deal Verloren',
            ],
            'Sale.update.updated' => [
                'EN' => 'Deal Updated',
                'PT' => 'Negócio Atualizado',
                'ES' => 'Negocio Actualizado',
                'DE' => 'Deal Aktualisiert',
            ],
        ];
    }

    /**
     * Description templates, keyed by "{recordableType}.{type}.{action}" with
     * an optional ".{variant}" suffix for cases that need alternate wording
     * depending on which params are available.
     */
    private static function descriptions(): array
    {
        return [
            'Lead.creation.created' => [
                'EN' => '{leadName} was added as a new lead',
                'PT' => '{leadName} foi adicionado como um novo lead',
                'ES' => '{leadName} fue agregado como un nuevo lead',
                'DE' => '{leadName} wurde als neuer Lead hinzugefügt',
            ],
            'Lead.whatsapp_chat.chat_started' => [
                'EN' => "New WhatsApp conversation started via instance '{instanceName}'",
                'PT' => "Nova conversa do WhatsApp iniciada via instância '{instanceName}'",
                'ES' => "Nueva conversación de WhatsApp iniciada a través de la instancia '{instanceName}'",
                'DE' => "Neue WhatsApp-Unterhaltung über Instanz '{instanceName}' gestartet",
            ],
            'Lead.instagram_chat.chat_started' => [
                'EN' => 'New Instagram conversation started via @{username}',
                'PT' => 'Nova conversa do Instagram iniciada via @{username}',
                'ES' => 'Nueva conversación de Instagram iniciada a través de @{username}',
                'DE' => 'Neue Instagram-Unterhaltung über @{username} gestartet',
            ],
            'Lead.lost.lost' => [
                'EN' => '{reason}',
                'PT' => '{reason}',
                'ES' => '{reason}',
                'DE' => '{reason}',
            ],
            'Lead.creation.created.via_api' => [
                'EN' => 'Lead created via external integration',
                'PT' => 'Lead criado via integração externa',
                'ES' => 'Lead creado mediante integración externa',
                'DE' => 'Lead über externe Integration erstellt',
            ],
            'Lead.creation.created.via_api_named' => [
                'EN' => "Lead created via external integration using API token '{apiTokenName}'",
                'PT' => "Lead criado via integração externa usando o token de API '{apiTokenName}'",
                'ES' => "Lead creado mediante integración externa usando el token de API '{apiTokenName}'",
                'DE' => "Lead über externe Integration mit API-Token '{apiTokenName}' erstellt",
            ],
            'Lead.stage_change.moved.with_from' => [
                'EN' => "{leadName} was moved from '{fromStage}' to '{toStage}'",
                'PT' => "{leadName} foi movido de '{fromStage}' para '{toStage}'",
                'ES' => "{leadName} fue movido de '{fromStage}' a '{toStage}'",
                'DE' => "{leadName} wurde von '{fromStage}' zu '{toStage}' verschoben",
            ],
            'Lead.stage_change.moved.no_from' => [
                'EN' => "{leadName} was moved to '{toStage}'",
                'PT' => "{leadName} foi movido para '{toStage}'",
                'ES' => "{leadName} fue movido a '{toStage}'",
                'DE' => "{leadName} wurde zu '{toStage}' verschoben",
            ],
            'Lead.stage_change.moved.to_funnel' => [
                'EN' => "{leadName} was moved to funnel '{funnelName}'",
                'PT' => "{leadName} foi movido para o funil '{funnelName}'",
                'ES' => "{leadName} fue movido al funnel '{funnelName}'",
                'DE' => "{leadName} wurde in den Funnel '{funnelName}' verschoben",
            ],
            'Sale.creation.created.with_lead' => [
                'EN' => "Deal '{dealName}' created for {leadName}",
                'PT' => "Negócio '{dealName}' criado para {leadName}",
                'ES' => "Negocio '{dealName}' creado para {leadName}",
                'DE' => "Deal '{dealName}' für {leadName} erstellt",
            ],
            'Sale.creation.created.no_lead' => [
                'EN' => "Deal '{dealName}' created",
                'PT' => "Negócio '{dealName}' criado",
                'ES' => "Negocio '{dealName}' creado",
                'DE' => "Deal '{dealName}' erstellt",
            ],
            'Sale.deletion.deleted' => [
                'EN' => "Deal '{dealName}' deleted",
                'PT' => "Negócio '{dealName}' excluído",
                'ES' => "Negocio '{dealName}' eliminado",
                'DE' => "Deal '{dealName}' gelöscht",
            ],
            'Sale.stage_change.won' => [
                'EN' => "New sale! '{dealName}'{leadPart}{ownerPart}",
                'PT' => "Nova venda! '{dealName}'{leadPart}{ownerPart}",
                'ES' => "¡Nueva venta! '{dealName}'{leadPart}{ownerPart}",
                'DE' => "Neuer Verkauf! '{dealName}'{leadPart}{ownerPart}",
            ],
            'Sale.stage_change.lost.with_from' => [
                'EN' => "'{dealName}' marked as lost — moved from '{fromStage}' to '{toStage}'",
                'PT' => "'{dealName}' marcado como perdido — movido de '{fromStage}' para '{toStage}'",
                'ES' => "'{dealName}' marcado como perdido — movido de '{fromStage}' a '{toStage}'",
                'DE' => "'{dealName}' als verloren markiert — verschoben von '{fromStage}' zu '{toStage}'",
            ],
            'Sale.stage_change.lost.no_from' => [
                'EN' => "'{dealName}' marked as lost in stage '{toStage}'",
                'PT' => "'{dealName}' marcado como perdido na etapa '{toStage}'",
                'ES' => "'{dealName}' marcado como perdido en la etapa '{toStage}'",
                'DE' => "'{dealName}' als verloren markiert in Phase '{toStage}'",
            ],
            'Sale.stage_change.moved.with_from' => [
                'EN' => "'{dealName}' moved from '{fromStage}' to '{toStage}'",
                'PT' => "'{dealName}' movido de '{fromStage}' para '{toStage}'",
                'ES' => "'{dealName}' movido de '{fromStage}' a '{toStage}'",
                'DE' => "'{dealName}' verschoben von '{fromStage}' zu '{toStage}'",
            ],
            'Sale.stage_change.moved.no_from' => [
                'EN' => "'{dealName}' moved to '{toStage}'",
                'PT' => "'{dealName}' movido para '{toStage}'",
                'ES' => "'{dealName}' movido a '{toStage}'",
                'DE' => "'{dealName}' verschoben zu '{toStage}'",
            ],
            'Sale.status_change.closed' => [
                'EN' => "New sale! '{dealName}'{leadPart}{ownerPart}",
                'PT' => "Nova venda! '{dealName}'{leadPart}{ownerPart}",
                'ES' => "¡Nueva venta! '{dealName}'{leadPart}{ownerPart}",
                'DE' => "Neuer Verkauf! '{dealName}'{leadPart}{ownerPart}",
            ],
            'Sale.status_change.lost' => [
                'EN' => "'{dealName}' marked as lost",
                'PT' => "'{dealName}' marcado como perdido",
                'ES' => "'{dealName}' marcado como perdido",
                'DE' => "'{dealName}' als verloren markiert",
            ],
            'Sale.update.updated' => [
                'EN' => 'Deal "{dealName}" updated by {userName}',
                'PT' => 'Negócio "{dealName}" atualizado por {userName}',
                'ES' => 'Negocio "{dealName}" actualizado por {userName}',
                'DE' => 'Deal "{dealName}" aktualisiert von {userName}',
            ],
        ];
    }
}
