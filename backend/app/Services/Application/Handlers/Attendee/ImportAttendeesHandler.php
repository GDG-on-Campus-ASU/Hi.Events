<?php

namespace HiEvents\Services\Application\Handlers\Attendee;

use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Exceptions\InvalidProductPriceId;
use HiEvents\Exceptions\NoTicketsAvailableException;
use HiEvents\Imports\AttendeesImport;
use HiEvents\Locale;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\DTO\CreateAttendeeDTO;
use HiEvents\Services\Application\Handlers\Attendee\DTO\ImportAttendeesDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Throwable;

class ImportAttendeesHandler
{
    private ?Collection $eventProducts = null;

    public function __construct(
        private readonly CreateAttendeeHandler $createAttendeeHandler,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
    ) {}

    /**
     * @return array{successful: int, failed: int, skipped: int, errors: array}
     */
    public function handle(ImportAttendeesDTO $dto): array
    {
        $import = new AttendeesImport;

        try {
            Excel::import($import, $dto->file);
        } catch (Throwable $e) {
            throw new RuntimeException(__('Failed to parse the CSV file. Please ensure it is a valid CSV format.'));
        }

        $rows = $import->getRows();

        // Validate required columns exist
        if ($rows->isEmpty()) {
            throw new RuntimeException(__('The CSV file is empty or has no data rows.'));
        }

        $firstRow = $rows->first();
        $this->validateRequiredColumns($firstRow);

        // Load products for this event
        $this->loadEventProducts($dto->event_id);

        $successful = 0;
        $failed = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // +2 because index starts at 0 and row 1 is headers

            try {
                $attendeeData = $this->mapRowToAttendeeDTO($row, $dto);

                if ($attendeeData === null) {
                    continue;
                }

                // Check if attendee with this email already exists for this event and product
                if ($this->attendeeExists($attendeeData->email, $dto->event_id, $attendeeData->product_id)) {
                    $skipped++;
                    Log::info('Duplicate attendee skipped during CSV import', [
                        'row' => $rowNumber,
                        'email' => $attendeeData->email,
                        'event_id' => $dto->event_id,
                        'product_id' => $attendeeData->product_id,
                    ]);

                    continue;
                }

                $this->createAttendeeHandler->handle($attendeeData);
                $successful++;
            } catch (InvalidArgumentException $e) {
                $failed++;
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => $e->getMessage(),
                ];
            } catch (NoTicketsAvailableException $e) {
                $failed++;
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => $e->getMessage(),
                ];
            } catch (InvalidProductPriceId $e) {
                $failed++;
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => $e->getMessage(),
                ];
            } catch (Throwable $e) {
                $failed++;
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => __('An unexpected error occurred while processing this row.'),
                ];
            }
        }

        return [
            'successful' => $successful,
            'failed' => $failed,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Check if an attendee with the given email already exists for the specified event and product.
     */
    private function attendeeExists(string $email, int $eventId, int $productId): bool
    {
        $existingAttendee = $this->attendeeRepository->findFirstWhere([
            AttendeeDomainObjectAbstract::EMAIL => strtolower($email),
            AttendeeDomainObjectAbstract::EVENT_ID => $eventId,
            AttendeeDomainObjectAbstract::PRODUCT_ID => $productId,
        ]);

        return $existingAttendee !== null;
    }

    private function validateRequiredColumns(Collection $row): void
    {
        $requiredColumns = [
            'firstName' => ['firstname', 'first_name', 'first name'],
            'lastName' => ['lastname', 'last_name', 'last name'],
            'email' => ['email', 'email_address', 'email address'],
            'language' => ['language', 'locale', 'lang'],
            'ticket' => ['ticket', 'ticket_name', 'product', 'product_name'],
            'paid' => ['paid', 'amount_paid', 'amount', 'price'],
        ];

        $missingColumns = [];
        foreach ($requiredColumns as $displayName => $possibleNames) {
            $found = false;
            foreach ($possibleNames as $name) {
                if ($row->has($name)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $missingColumns[] = $displayName;
            }
        }

        if (! empty($missingColumns)) {
            throw new RuntimeException(
                __('CSV is missing required columns: :columns', ['columns' => implode(', ', $missingColumns)])
            );
        }
    }

    private function loadEventProducts(int $eventId): void
    {
        $this->eventProducts = $this->productRepository
            ->loadRelation(ProductPriceDomainObject::class)
            ->findWhere([
                ProductDomainObjectAbstract::EVENT_ID => $eventId,
                ProductDomainObjectAbstract::PRODUCT_TYPE => ProductType::TICKET->name,
            ]);
    }

    private function findProductByName(string $ticketName): ?ProductDomainObject
    {
        $normalizedName = strtolower(trim($ticketName));

        /** @var ProductDomainObject $product */
        foreach ($this->eventProducts as $product) {
            // Sanitize product title the same way as ticket name for comparison
            $sanitizedTitle = $this->sanitizeGenericField($product->getTitle());
            if (strtolower(trim($sanitizedTitle)) === $normalizedName) {
                return $product;
            }
        }

        return null;
    }

    private function mapLanguageToLocale(string $language): string
    {
        $languageMap = [
            'english' => 'en',
            'german' => 'de',
            'french' => 'fr',
            'italian' => 'it',
            'dutch' => 'nl',
            'hungarian' => 'hu',
            'spanish' => 'es',
            'portuguese' => 'pt',
            'portuguese (brazil)' => 'pt-br',
            'chinese' => 'zh-cn',
            'cantonese' => 'zh-hk',
            'vietnamese' => 'vi',
            'turkish' => 'tr',
        ];

        $normalizedLanguage = strtolower(trim($language));

        // Check if already a valid locale code
        $validLocales = Locale::getSupportedLocales();
        if (in_array($normalizedLanguage, $validLocales)) {
            return $normalizedLanguage;
        }

        // Map language name to locale
        return $languageMap[$normalizedLanguage] ?? 'en';
    }

    private function mapRowToAttendeeDTO(Collection $row, ImportAttendeesDTO $dto): ?CreateAttendeeDTO
    {
        $firstName = $this->getRowValue($row, ['firstname', 'first_name', 'first name']);
        $lastName = $this->getRowValue($row, ['lastname', 'last_name', 'last name']);
        $email = $this->getRowValue($row, ['email', 'email_address', 'email address']);
        $language = $this->getRowValue($row, ['language', 'locale', 'lang']) ?? 'English';
        $ticketName = $this->getRowValue($row, ['ticket', 'ticket_name', 'product', 'product_name']);
        $paid = $this->getRowValue($row, ['paid', 'amount_paid', 'amount', 'price']);

        // Sanitize inputs to prevent malicious data and ensure data integrity
        $firstName = $this->sanitizeName($firstName);
        $lastName = $this->sanitizeName($lastName);
        $email = $this->sanitizeEmail($email);
        $language = $this->sanitizeGenericField($language);
        $ticketName = $this->sanitizeGenericField($ticketName);
        $paid = $this->sanitizeGenericField($paid);

        // Skip empty rows
        if (empty($firstName) && empty($lastName) && empty($email)) {
            return null;
        }

        // Validate required fields
        if (empty($firstName)) {
            throw new InvalidArgumentException(__('First name is required'));
        }

        if (empty($lastName)) {
            throw new InvalidArgumentException(__('Last name is required'));
        }

        if (empty($email)) {
            throw new InvalidArgumentException(__('Email is required'));
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(__('Invalid email format'));
        }

        if (empty($ticketName)) {
            throw new InvalidArgumentException(__('Ticket is required'));
        }

        if ($paid === null || $paid === '') {
            throw new InvalidArgumentException(__('Amount paid is required'));
        }

        // Find the product by name
        $product = $this->findProductByName($ticketName);
        if (! $product) {
            throw new InvalidArgumentException(
                __('Ticket ":ticket" not found in this event', ['ticket' => $ticketName])
            );
        }

        // Get the first price ID for the product
        $productPriceId = null;
        if ($product->getProductPrices() && $product->getProductPrices()->isNotEmpty()) {
            $productPriceId = $product->getProductPrices()->first()->getId();
        }

        // Parse paid amount
        $amountPaid = (float) str_replace(',', '.', $paid);
        if ($amountPaid < 0) {
            throw new InvalidArgumentException(__('Amount paid cannot be negative'));
        }

        // Map language to locale
        $locale = $this->mapLanguageToLocale($language);

        return CreateAttendeeDTO::fromArray([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'product_id' => $product->getId(),
            'product_price_id' => $productPriceId,
            'event_id' => $dto->event_id,
            'send_confirmation_email' => $dto->send_confirmation_email,
            'amount_paid' => $amountPaid,
            'locale' => $locale,
            'taxes_and_fees' => [],
        ]);
    }

    /**
     * Sanitize name fields (first name, last name).
     * Replaces dashes, dots, and underscores with spaces.
     * Removes any special characters, keeping only Unicode letters, numbers, and spaces.
     */
    private function sanitizeName(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // Replace dashes, dots, and underscores with spaces
        $value = str_replace(['-', '.', '_'], ' ', $value);

        // Remove any character that is not a Unicode letter, number, or space
        $value = preg_replace('/[^\p{L}0-9\s]/u', '', $value);

        // Normalize multiple spaces to single space and trim
        $value = preg_replace('/\s+/', ' ', $value);

        return trim($value);
    }

    /**
     * Sanitize email field.
     * Normalizes to lowercase and removes any character that is not valid in an email address.
     */
    private function sanitizeEmail(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // Normalize email to lowercase for case-insensitive duplicate detection
        $value = strtolower($value);

        // Remove any character that is not valid in an email address (letters, numbers, and common email symbols)
        $value = preg_replace('/[^a-z0-9.@_+\-]/', '', $value);

        return trim($value);
    }

    /**
     * Sanitize generic fields (language, ticket name, paid amount).
     * Removes any character that is not a Unicode letter, number, space, dot, dash, or underscore.
     */
    private function sanitizeGenericField(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // Remove any character that is not a Unicode letter, number, space, dot, dash, or underscore
        $value = preg_replace('/[^\p{L}0-9\s.\-_]/u', '', $value);

        return trim($value);
    }

    private function getRowValue(Collection $row, array $possibleKeys): ?string
    {
        foreach ($possibleKeys as $key) {
            $value = $row->get($key);
            if ($value !== null && $value !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }
}
