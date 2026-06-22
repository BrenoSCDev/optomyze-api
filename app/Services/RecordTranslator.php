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
            'Lead.chat_session.started' => [
                'EN' => 'Conversation Started',
                'PT' => 'Conversa Iniciada',
                'ES' => 'Conversación Iniciada',
                'DE' => 'Gespräch Gestartet',
            ],
            'Lead.chat_session.closed' => [
                'EN' => 'Conversation Closed',
                'PT' => 'Conversa Encerrada',
                'ES' => 'Conversación Cerrada',
                'DE' => 'Gespräch Beendet',
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
            'Lead.assignment.assigned' => [
                'EN' => 'Lead Assigned',
                'PT' => 'Lead Atribuído',
                'ES' => 'Lead Asignado',
                'DE' => 'Lead Zugewiesen',
            ],
            'Lead.assignment.unassigned' => [
                'EN' => 'Lead Unassigned',
                'PT' => 'Lead Desatribuído',
                'ES' => 'Lead Desasignado',
                'DE' => 'Lead Nicht Mehr Zugewiesen',
            ],
            'Sale.assignment.assigned' => [
                'EN' => 'Deal Assigned',
                'PT' => 'Negócio Atribuído',
                'ES' => 'Negocio Asignado',
                'DE' => 'Deal Zugewiesen',
            ],
            'Sale.assignment.unassigned' => [
                'EN' => 'Deal Unassigned',
                'PT' => 'Negócio Desatribuído',
                'ES' => 'Negocio Desasignado',
                'DE' => 'Deal Nicht Mehr Zugewiesen',
            ],
            'Lead.task.created' => [
                'EN' => 'Task Created',
                'PT' => 'Tarefa Criada',
                'ES' => 'Tarea Creada',
                'DE' => 'Aufgabe Erstellt',
            ],
            'Lead.task.updated' => [
                'EN' => 'Task Updated',
                'PT' => 'Tarefa Atualizada',
                'ES' => 'Tarea Actualizada',
                'DE' => 'Aufgabe Aktualisiert',
            ],
            'Lead.task.completed' => [
                'EN' => 'Task Completed',
                'PT' => 'Tarefa Concluída',
                'ES' => 'Tarea Completada',
                'DE' => 'Aufgabe Abgeschlossen',
            ],
            'Lead.tag_change.added' => [
                'EN' => 'Tag Added',
                'PT' => 'Tag Adicionada',
                'ES' => 'Etiqueta Añadida',
                'DE' => 'Tag Hinzugefügt',
            ],
            'Lead.tag_change.removed' => [
                'EN' => 'Tag Removed',
                'PT' => 'Tag Removida',
                'ES' => 'Etiqueta Eliminada',
                'DE' => 'Tag Entfernt',
            ],
            'Sale.tag_change.added' => [
                'EN' => 'Tag Added',
                'PT' => 'Tag Adicionada',
                'ES' => 'Etiqueta Añadida',
                'DE' => 'Tag Hinzugefügt',
            ],
            'Sale.tag_change.removed' => [
                'EN' => 'Tag Removed',
                'PT' => 'Tag Removida',
                'ES' => 'Etiqueta Eliminada',
                'DE' => 'Tag Entfernt',
            ],
            'Lead.appointment.created' => [
                'EN' => 'Appointment Created',
                'PT' => 'Agendamento Criado',
                'ES' => 'Cita Creada',
                'DE' => 'Termin Erstellt',
            ],
            'Lead.appointment.updated' => [
                'EN' => 'Appointment Updated',
                'PT' => 'Agendamento Atualizado',
                'ES' => 'Cita Actualizada',
                'DE' => 'Termin Aktualisiert',
            ],
            'Lead.appointment.completed' => [
                'EN' => 'Appointment Completed',
                'PT' => 'Agendamento Concluído',
                'ES' => 'Cita Completada',
                'DE' => 'Termin Abgeschlossen',
            ],
            'Lead.observation.created' => [
                'EN' => 'Observation Added',
                'PT' => 'Observação Adicionada',
                'ES' => 'Observación Añadida',
                'DE' => 'Beobachtung Hinzugefügt',
            ],
            'Lead.document.added' => [
                'EN' => 'Document Added',
                'PT' => 'Documento Adicionado',
                'ES' => 'Documento Añadido',
                'DE' => 'Dokument Hinzugefügt',
            ],
            'Sale.document.added' => [
                'EN' => 'Document Added',
                'PT' => 'Documento Adicionado',
                'ES' => 'Documento Añadido',
                'DE' => 'Dokument Hinzugefügt',
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
            'Lead.chat_session.started' => [
                'EN' => 'New conversation started on {channel}',
                'PT' => 'Nova conversa iniciada no {channel}',
                'ES' => 'Nueva conversación iniciada en {channel}',
                'DE' => 'Neues Gespräch auf {channel} gestartet',
            ],
            'Lead.chat_session.closed' => [
                'EN' => "Conversation closed — {closingReason} ({durationMinutes} min)",
                'PT' => "Conversa encerrada — {closingReason} ({durationMinutes} min)",
                'ES' => "Conversación cerrada — {closingReason} ({durationMinutes} min)",
                'DE' => "Gespräch beendet — {closingReason} ({durationMinutes} Min.)",
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
            'Lead.assignment.assigned.initial' => [
                'EN' => '{leadName} assigned to {newUserName}',
                'PT' => '{leadName} atribuído a {newUserName}',
                'ES' => '{leadName} asignado a {newUserName}',
                'DE' => '{leadName} an {newUserName} zugewiesen',
            ],
            'Lead.assignment.assigned.reassigned' => [
                'EN' => '{leadName} reassigned from {oldUserName} to {newUserName}',
                'PT' => '{leadName} reatribuído de {oldUserName} para {newUserName}',
                'ES' => '{leadName} reasignado de {oldUserName} a {newUserName}',
                'DE' => '{leadName} von {oldUserName} zu {newUserName} umgewiesen',
            ],
            'Lead.assignment.unassigned' => [
                'EN' => '{leadName} unassigned from {oldUserName}',
                'PT' => '{leadName} desatribuído de {oldUserName}',
                'ES' => '{leadName} desasignado de {oldUserName}',
                'DE' => '{leadName} von {oldUserName} entfernt',
            ],
            'Sale.assignment.assigned.initial' => [
                'EN' => "'{dealName}' assigned to {newUserName}",
                'PT' => "'{dealName}' atribuído a {newUserName}",
                'ES' => "'{dealName}' asignado a {newUserName}",
                'DE' => "'{dealName}' an {newUserName} zugewiesen",
            ],
            'Sale.assignment.assigned.reassigned' => [
                'EN' => "'{dealName}' reassigned from {oldUserName} to {newUserName}",
                'PT' => "'{dealName}' reatribuído de {oldUserName} para {newUserName}",
                'ES' => "'{dealName}' reasignado de {oldUserName} a {newUserName}",
                'DE' => "'{dealName}' von {oldUserName} zu {newUserName} umgewiesen",
            ],
            'Sale.assignment.unassigned' => [
                'EN' => "'{dealName}' unassigned from {oldUserName}",
                'PT' => "'{dealName}' desatribuído de {oldUserName}",
                'ES' => "'{dealName}' desasignado de {oldUserName}",
                'DE' => "'{dealName}' von {oldUserName} entfernt",
            ],
            'Lead.task.created' => [
                'EN' => 'Task "{taskTitle}" created for {leadName}',
                'PT' => 'Tarefa "{taskTitle}" criada para {leadName}',
                'ES' => 'Tarea "{taskTitle}" creada para {leadName}',
                'DE' => 'Aufgabe "{taskTitle}" für {leadName} erstellt',
            ],
            'Lead.task.updated' => [
                'EN' => 'Task "{taskTitle}" updated',
                'PT' => 'Tarefa "{taskTitle}" atualizada',
                'ES' => 'Tarea "{taskTitle}" actualizada',
                'DE' => 'Aufgabe "{taskTitle}" aktualisiert',
            ],
            'Lead.task.completed' => [
                'EN' => 'Task "{taskTitle}" marked as completed',
                'PT' => 'Tarefa "{taskTitle}" marcada como concluída',
                'ES' => 'Tarea "{taskTitle}" marcada como completada',
                'DE' => 'Aufgabe "{taskTitle}" als abgeschlossen markiert',
            ],
            'Lead.tag_change.added' => [
                'EN' => "Tag '{tagName}' added to {leadName}",
                'PT' => "Tag '{tagName}' adicionada a {leadName}",
                'ES' => "Etiqueta '{tagName}' añadida a {leadName}",
                'DE' => "Tag '{tagName}' zu {leadName} hinzugefügt",
            ],
            'Lead.tag_change.removed' => [
                'EN' => "Tag '{tagName}' removed from {leadName}",
                'PT' => "Tag '{tagName}' removida de {leadName}",
                'ES' => "Etiqueta '{tagName}' eliminada de {leadName}",
                'DE' => "Tag '{tagName}' von {leadName} entfernt",
            ],
            'Sale.tag_change.added' => [
                'EN' => "Tag '{tagName}' added to '{dealName}'",
                'PT' => "Tag '{tagName}' adicionada a '{dealName}'",
                'ES' => "Etiqueta '{tagName}' añadida a '{dealName}'",
                'DE' => "Tag '{tagName}' zu '{dealName}' hinzugefügt",
            ],
            'Sale.tag_change.removed' => [
                'EN' => "Tag '{tagName}' removed from '{dealName}'",
                'PT' => "Tag '{tagName}' removida de '{dealName}'",
                'ES' => "Etiqueta '{tagName}' eliminada de '{dealName}'",
                'DE' => "Tag '{tagName}' von '{dealName}' entfernt",
            ],
            'Lead.appointment.created' => [
                'EN' => 'Appointment "{appointmentTitle}" scheduled for {leadName}',
                'PT' => 'Agendamento "{appointmentTitle}" criado para {leadName}',
                'ES' => 'Cita "{appointmentTitle}" creada para {leadName}',
                'DE' => 'Termin "{appointmentTitle}" für {leadName} geplant',
            ],
            'Lead.appointment.updated' => [
                'EN' => 'Appointment "{appointmentTitle}" updated',
                'PT' => 'Agendamento "{appointmentTitle}" atualizado',
                'ES' => 'Cita "{appointmentTitle}" actualizada',
                'DE' => 'Termin "{appointmentTitle}" aktualisiert',
            ],
            'Lead.appointment.completed' => [
                'EN' => 'Appointment "{appointmentTitle}" marked as completed',
                'PT' => 'Agendamento "{appointmentTitle}" marcado como concluído',
                'ES' => 'Cita "{appointmentTitle}" marcada como completada',
                'DE' => 'Termin "{appointmentTitle}" als abgeschlossen markiert',
            ],
            'Lead.observation.created' => [
                'EN' => 'New observation added to {leadName}',
                'PT' => 'Nova observação adicionada a {leadName}',
                'ES' => 'Nueva observación añadida a {leadName}',
                'DE' => 'Neue Beobachtung zu {leadName} hinzugefügt',
            ],
            'Lead.document.added' => [
                'EN' => "Document '{documentTitle}' attached to {leadName}",
                'PT' => "Documento '{documentTitle}' anexado a {leadName}",
                'ES' => "Documento '{documentTitle}' adjuntado a {leadName}",
                'DE' => "Dokument '{documentTitle}' an {leadName} angehängt",
            ],
            'Sale.document.added' => [
                'EN' => "Document '{documentTitle}' attached to '{dealName}'",
                'PT' => "Documento '{documentTitle}' anexado a '{dealName}'",
                'ES' => "Documento '{documentTitle}' adjuntado a '{dealName}'",
                'DE' => "Dokument '{documentTitle}' an '{dealName}' angehängt",
            ],
        ];
    }
}
