<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

use Glpi\Form\ServiceCatalog\ItemRequest;
use Glpi\Form\ServiceCatalog\Provider\LeafProviderInterface;

/** @implements LeafProviderInterface<ServiceCatalogItem> */
final class ServiceCatalogProvider implements LeafProviderInterface
{
    public function getItems(ItemRequest $item_request): array
    {
        if (!Schema::ready() || !Access::right('request') || Access::userId() <= 0) {
            return [];
        }
        // These offerings belong to the catalog root, not every native category.
        if (($item_request->getCategoryID() ?? 0) > 0) {
            return [];
        }
        $filter = self::normalize($item_request->getFilter());
        $offerings = [
            'room' => [__('Reservar sala', 'reservaplus'), __('Consulte salas, localização, capacidade e horários.', 'reservaplus'), 'sala salao reunião reuniao treinamento espaço espaco'],
            'equipment' => [__('Reservar equipamento', 'reservaplus'), __('Solicite equipamentos, acompanhe a retirada e a devolução.', 'reservaplus'), 'equipamento notebook laptop projetor empréstimo emprestimo kit'],
            'other' => [__('Reservar outro recurso', 'reservaplus'), __('Consulte os demais recursos disponíveis para reserva.', 'reservaplus'), 'recurso reserva'],
        ];
        $items = [];
        foreach ($offerings as $kind => [$title, $description, $aliases]) {
            if ($filter !== '' && !str_contains(self::normalize($title . ' ' . $description . ' ' . $aliases), $filter)) {
                continue;
            }
            // One scoped match is sufficient; never enumerate inventory as cards.
            if (ResourceService::search(['kind' => $kind, 'publication' => 'published'], 1, 0) === []) {
                continue;
            }
            $items[] = new ServiceCatalogItem($kind, $title, $description);
        }
        return $items;
    }

    public function getItemsLabel(): string
    {
        return __('Reservas', 'reservaplus');
    }

    public function getWeight(): int
    {
        return 40;
    }

    private static function normalize(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
        return strtr($value, ['á'=>'a', 'à'=>'a', 'â'=>'a', 'ã'=>'a', 'é'=>'e', 'ê'=>'e', 'í'=>'i', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o', 'ú'=>'u', 'ç'=>'c']);
    }
}
