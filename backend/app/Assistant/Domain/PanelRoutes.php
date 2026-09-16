<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain;

/**
 * The map of the panel the assistant is allowed to point at. Paths are relative
 * to the frontend and mirror frontend/src/router.tsx; the model never composes
 * a URL itself, it asks for one of these by name.
 */
final class PanelRoutes
{
    /** @var array<string, array{path: string, label: string, needs_event: bool, steps: string}> */
    public const DESTINATIONS = [
        'connect_mercadopago' => [
            'path' => '/account/payment',
            'label' => 'Conectar MercadoPago',
            'needs_event' => false,
            'steps' => 'Cuenta → Pagos → botón "Conectar MercadoPago". Te lleva a MercadoPago para autorizar; al volver, el panel muestra "Conectado". Es una vez por cuenta y vale para todos tus eventos.',
        ],
        'publish_event' => [
            'path' => '/manage/event/{event}/dashboard',
            'label' => 'Publicar el evento',
            'needs_event' => true,
            'steps' => 'Arriba a la izquierda del panel del evento está el estado "Borrador": hacé clic y elegí "Publicar". Antes conviene tener al menos una entrada y MercadoPago conectado.',
        ],
        'event_tickets' => [
            'path' => '/manage/event/{event}/products',
            'label' => 'Entradas y productos',
            'needs_event' => true,
            'steps' => 'Ahí se crean y editan los tipos de entrada: precio, cupo, fechas de venta, y si se ocultan o se muestran.',
        ],
        'event_settings' => [
            'path' => '/manage/event/{event}/settings',
            'label' => 'Configuración del evento',
            'needs_event' => true,
            'steps' => 'Título, fechas, lugar, descripción, mensajes del checkout y SEO.',
        ],
        'event_homepage_designer' => [
            'path' => '/manage/event/{event}/homepage-designer',
            'label' => 'Diseñador de la página',
            'needs_event' => true,
            'steps' => 'Colores, fondo y tipografía de la página pública del evento, con vista previa en vivo.',
        ],
        'event_promo_codes' => [
            'path' => '/manage/event/{event}/promo-codes',
            'label' => 'Códigos promocionales',
            'needs_event' => true,
            'steps' => 'Crear códigos con descuento fijo o porcentual, con límite de usos y vencimiento.',
        ],
        'event_orders' => [
            'path' => '/manage/event/{event}/orders',
            'label' => 'Pedidos',
            'needs_event' => true,
            'steps' => 'Todas las órdenes del evento: buscar, reenviar entradas, reembolsar o cancelar.',
        ],
        'event_attendees' => [
            'path' => '/manage/event/{event}/attendees',
            'label' => 'Asistentes',
            'needs_event' => true,
            'steps' => 'Lista de asistentes con su entrada; se pueden editar, exportar o marcar ingreso a mano.',
        ],
        'event_checkin' => [
            'path' => '/manage/event/{event}/check-in',
            'label' => 'Listas de ingreso',
            'needs_event' => true,
            'steps' => 'Crear la lista de ingreso y compartir el link del escáner con quien esté en la puerta.',
        ],
        'event_messages' => [
            'path' => '/manage/event/{event}/messages',
            'label' => 'Mensajes a compradores',
            'needs_event' => true,
            'steps' => 'Enviar un email a todos los compradores o a los de una entrada en particular.',
        ],
        'event_capacity' => [
            'path' => '/manage/event/{event}/capacity-assignments',
            'label' => 'Cupos',
            'needs_event' => true,
            'steps' => 'Un cupo compartido entre varias entradas (por ejemplo, la capacidad total del lugar).',
        ],
        'event_questions' => [
            'path' => '/manage/event/{event}/questions',
            'label' => 'Preguntas del checkout',
            'needs_event' => true,
            'steps' => 'Datos extra que se le piden al comprador o a cada asistente al comprar.',
        ],
        'event_seating' => [
            'path' => '/manage/event/{event}/seating',
            'label' => 'Butacas',
            'needs_event' => true,
            'steps' => 'Armar el mapa de butacas y asociar cada sección a una entrada.',
        ],
        'event_widget' => [
            'path' => '/manage/event/{event}/widget',
            'label' => 'Widget para tu web',
            'needs_event' => true,
            'steps' => 'El código para vender desde tu propia página web.',
        ],
        'organizer_settings' => [
            'path' => '/manage/organizer/{organizer}/settings',
            'label' => 'Ajustes del organizador',
            'needs_event' => false,
            'steps' => 'Nombre, logo, moneda, zona horaria y quién paga la comisión.',
        ],
        'organizer_events' => [
            'path' => '/manage/organizer/{organizer}/events',
            'label' => 'Tus eventos',
            'needs_event' => false,
            'steps' => 'Todos los eventos del organizador, próximos y pasados.',
        ],
        'organizer_reports' => [
            'path' => '/manage/organizer/{organizer}/reports',
            'label' => 'Informes',
            'needs_event' => false,
            'steps' => 'Ingresos, performance por evento, impuestos y check-in, exportables.',
        ],
        'organizer_homepage_designer' => [
            'path' => '/manage/organizer/{organizer}/organizer-homepage-designer',
            'label' => 'Página del organizador',
            'needs_event' => false,
            'steps' => 'La página pública con todos tus eventos.',
        ],
        'team' => [
            'path' => '/account/users',
            'label' => 'Equipo',
            'needs_event' => false,
            'steps' => 'Invitar a alguien más a administrar la cuenta.',
        ],
        'taxes_and_fees' => [
            'path' => '/account/taxes-and-fees',
            'label' => 'Impuestos y cargos',
            'needs_event' => false,
            'steps' => 'Impuestos o cargos por servicio que se suman al precio de la entrada.',
        ],
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::DESTINATIONS);
    }
}
