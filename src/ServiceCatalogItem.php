<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

use Glpi\Form\ServiceCatalog\ServiceCatalogLeafInterface;
use Glpi\UI\IllustrationManager;

/** A service offering, never an inventory row or an already-created request. */
final class ServiceCatalogItem implements ServiceCatalogLeafInterface
{
    public array $fields = ['is_pinned' => 0];

    public function __construct(private string $kind, private string $title, private string $description)
    {
    }

    public function getServiceCatalogLink(): string
    {
        return BookingView::url('booking.form.php', ['kind' => $this->kind]);
    }

    public function getServiceCatalogItemTitle(): string
    {
        return $this->title;
    }

    public function getServiceCatalogItemDescription(): string
    {
        return $this->description;
    }

    public function getServiceCatalogItemIllustration(): string
    {
        return IllustrationManager::DEFAULT_ILLUSTRATION;
    }

    public function isServiceCatalogItemPinned(): bool
    {
        return false;
    }
}
