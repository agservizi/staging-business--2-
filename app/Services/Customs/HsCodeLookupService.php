<?php
declare(strict_types=1);

namespace App\Services\Customs;

use App\Services\Customs\Taric\TaricException;
use App\Services\Customs\Taric\TaricGoodsService;

use Throwable;

use function is_array;
use function is_string;
use function trim;

final class HsCodeLookupService
{
    private HsCodeDatasetRepository $repository;
    private ?TaricGoodsService $taricService = null;
    private bool $taricUnavailable = false;

    /**
     * @var array<string, array{code:string,description:string,declarable:bool,reference_date:string,language:string}>
     */
    private array $taricCache = [];
    private ?string $taricLastError = null;

    public function __construct(?HsCodeDatasetRepository $repository = null, ?TaricGoodsService $taricService = null)
    {
        $this->repository = $repository ?? new HsCodeDatasetRepository();
        if ($taricService instanceof TaricGoodsService) {
            $this->taricService = $taricService;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, int $limit = 10): array
    {
        $normalizedQuery = trim($query);
        if ($normalizedQuery === '') {
            return [];
        }

        $results = $this->repository->search($normalizedQuery, $limit);
        if ($results === []) {
            return [];
        }

        $enriched = [];
        foreach ($results as $item) {
            if (!is_array($item)) {
                continue;
            }
            $enriched[] = $this->enrichWithTaric($item);
        }

        return $enriched;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function enrichWithTaric(array $item): array
    {
        $originalCode = isset($item['code']) && is_string($item['code']) ? $item['code'] : '';
        if ($originalCode === '') {
            return $item;
        }

        if (isset($this->taricCache[$originalCode])) {
            return $this->applyTaricData($item, $this->taricCache[$originalCode]);
        }

        $service = $this->getTaricService();
        if ($service === null) {
            if ($this->taricLastError !== null) {
                $item['taric'] = [
                    'declarable' => false,
                    'reference_date' => null,
                    'language' => 'it',
                    'error' => $this->taricLastError,
                ];
            }

            return $item;
        }

        try {
            $taricData = $service->describe($originalCode);
            $this->taricCache[$originalCode] = $taricData;
            return $this->applyTaricData($item, $taricData);
        } catch (TaricException $exception) {
            $item['taric'] = [
                'declarable' => false,
                'reference_date' => null,
                'language' => 'it',
                'error' => $exception->getMessage(),
            ];

            if (!isset($item['descriptions']) || !is_array($item['descriptions'])) {
                $item['descriptions'] = [];
            }
            if (!isset($item['descriptions']['it']) || !is_string($item['descriptions']['it']) || trim($item['descriptions']['it']) === '') {
                $item['descriptions']['it'] = isset($item['description']) && is_string($item['description']) ? $item['description'] : $originalCode;
            }

            return $item;
        } catch (Throwable $exception) {
            $this->taricUnavailable = true;
            $this->taricLastError = $exception->getMessage();
            $item['taric'] = [
                'declarable' => false,
                'reference_date' => null,
                'language' => 'it',
                'error' => $exception->getMessage(),
            ];

            return $item;
        }
    }

    /**
     * @param array<string, mixed> $item
     * @param array{code:string,description:string,declarable:bool,reference_date:string,language:string} $taricData
     * @return array<string, mixed>
     */
    private function applyTaricData(array $item, array $taricData): array
    {
        $item['code'] = $taricData['code'];
        $item['description'] = $taricData['description'];

        if (!isset($item['descriptions']) || !is_array($item['descriptions'])) {
            $item['descriptions'] = [];
        }

        $item['descriptions']['it'] = $taricData['description'];
        $item['descriptions']['taric_language'] = $taricData['language'];

        $item['taric'] = [
            'declarable' => $taricData['declarable'],
            'reference_date' => $taricData['reference_date'],
            'language' => $taricData['language'],
        ];

        return $item;
    }

    private function getTaricService(): ?TaricGoodsService
    {
        if ($this->taricUnavailable) {
            return null;
        }

        if ($this->taricService instanceof TaricGoodsService) {
            return $this->taricService;
        }

        try {
            $this->taricService = new TaricGoodsService();
            return $this->taricService;
        } catch (TaricException $exception) {
            $this->taricUnavailable = true;
            $this->taricLastError = $exception->getMessage();
            return null;
        } catch (Throwable $exception) {
            $this->taricUnavailable = true;
            $this->taricLastError = $exception->getMessage();
            return null;
        }
    }
}
