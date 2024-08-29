<?php

namespace Yajra\DataTables\Services;

use Barryvdh\Snappy\PdfWrapper;
use Closure;
use Generator;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Maatwebsite\Excel\ExcelServiceProvider;
use Rap2hpoutre\FastExcel\FastExcel;
use Yajra\DataTables\Contracts\DataTableButtons;
use Yajra\DataTables\Contracts\DataTableScope;
use Yajra\DataTables\Exceptions\Exception;
use Yajra\DataTables\Html\Builder;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\QueryDataTable;
use Yajra\DataTables\Transformers\DataArrayTransformer;
use Yajra\DataTables\Utilities\Request;

abstract class DataTable implements DataTableButtons
{
    /**
     * DataTables print preview view.
     *
     * @phpstan-var view-string
     */
    protected string $printPreview = 'datatables::print';

    /**
     * Name of the dataTable variable.
     */
    protected string $dataTableVariable = 'dataTable';

    /**
     * List of columns to be excluded from export.
     */
    protected array $excludeFromExport = [];

    /**
     * List of columns to be excluded from printing.
     */
    protected array $excludeFromPrint = [];

    /**
     * List of columns to be exported.
     */
    protected string|array $exportColumns = '*';

    /**
     * List of columns to be printed.
     */
    protected string|array $printColumns = '*';

    /**
     * Query scopes.
     *
     * @var \Yajra\DataTables\Contracts\DataTableScope[]
     */
    protected array $scopes = [];

    /**
     * Html builder.
     */
    protected ?Builder $htmlBuilder = null;

    /**
     * Html builder extension callback.
     *
     * @var callable|null
     */
    protected $htmlCallback;

    /**
     * Export filename.
     */
    protected string $filename = '';

    /**
     * Custom attributes set on the class.
     */
    protected array $attributes = [];

    /**
     * Callback before sending the response.
     *
     * @var callable|null
     */
    protected $beforeCallback;

    /**
     * Callback after processing the response.
     *
     * @var callable|null
     */
    protected $responseCallback;

    /**
     * Available button actions. When calling an action, the value will be used
     * as the function name (so it should be available)
     * If you want to add or disable an action, overload and modify this property.
     */
    protected array $actions = ['print', 'csv', 'excel', 'pdf'];

    protected ?Request $request = null;

    /**
     * Flag to use fast-excel package for export.
     */
    protected bool $fastExcel = false;

    /**
     * Flag to enable/disable fast-excel callback.
     * Note: Disabling this flag can improve you export time.
     * Enabled by default to emulate the same output
     * with laravel-excel.
     */
    protected bool $fastExcelCallback = true;

    /**
     * Export class handler.
     *
     * @var class-string
     */
    protected string $exportClass = DataTablesExportHandler::class;

    /**
     * CSV export typewriter.
     */
    protected string $csvWriter = 'Csv';

    /**
     * Excel export typewriter.
     */
    protected string $excelWriter = 'Xlsx';

    /**
     * PDF export typewriter.
     */
    protected string $pdfWriter = 'Dompdf';

    public function __construct()
    {
        /** @var Request $request */
        $request = app('datatables.request');

        /** @var Builder $builder */
        $builder = app('datatables.html');

        $this->request = $request;
        $this->htmlBuilder = $builder;
    }

    /**
     * Process dataTables needed render output.
     *
     * @phpstan-param view-string|null $view
     *
     * @return mixed
     */
    public function render(?string $view = null, array $data = [], array $mergeData = [])
    {
        if ($this->request()->ajax() && $this->request()->wantsJson()) {
            return app()->call($this->ajax(...));
        }

        /** @var string $action */
        $action = $this->request()->get('action');
        $actionMethod = $action === 'print' ? 'printPreview' : $action;

        if (in_array($action, $this->actions) && method_exists($this, $actionMethod)) {
            /** @var callable $callback */
            $callback = [$this, $actionMethod];

            return app()->call($callback);
        }

        /** @phpstan-ignore-next-line  */
        return view($view, $data, $mergeData)->with($this->dataTableVariable, $this->getHtmlBuilder());
    }

    /**
     * Get DataTables Request instance.
     */
    public function request(): Request
    {
        if (! $this->request) {
            $this->request = app(Request::class);
        }

        return $this->request;
    }

    /**
     * Display ajax response.
     */
    public function ajax(): JsonResponse
    {
        $query = null;
        if (method_exists($this, 'query')) {
            /** @var EloquentBuilder|QueryBuilder|EloquentRelation $query */
            $query = app()->call([$this, 'query']);
            $query = $this->applyScopes($query);
        }

        /** @var \Yajra\DataTables\DataTableAbstract $dataTable */
        // @phpstan-ignore-next-line
        $dataTable = app()->call([$this, 'dataTable'], compact('query'));

        if (is_callable($this->beforeCallback)) {
            app()->call($this->beforeCallback, compact('dataTable'));
        }

        if (is_callable($this->responseCallback)) {
            $data = new Collection($dataTable->toArray());

            $response = app()->call($this->responseCallback, compact('data'));

            return new JsonResponse($response);
        }

        return $dataTable->toJson();
    }

    /**
     * Display printable view of datatables.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function printPreview(): Renderable
    {
        $data = $this->getDataForPrint();

        return view($this->printPreview, compact('data'));
    }

    /**
     * Get mapped columns versus final decorated output.
     */
    protected function getDataForPrint(): array
    {
        $columns = $this->printColumns();

        return $this->mapResponseToColumns($columns, 'printable');
    }

    /**
     * Get printable columns.
     */
    protected function printColumns(): array|Collection
    {
        return is_array($this->printColumns) ? $this->toColumnsCollection($this->printColumns) : $this->getPrintColumnsFromBuilder();
    }

    /**
     * Get filtered print columns definition from html builder.
     */
    protected function getPrintColumnsFromBuilder(): Collection
    {
        return $this->html()->removeColumn(...$this->excludeFromPrint)->getColumns();
    }

    /**
     * Get filtered export columns definition from html builder.
     */
    protected function getExportColumnsFromBuilder(): Collection
    {
        return $this->html()->removeColumn(...$this->excludeFromExport)->getColumns();
    }

    /**
     * Get columns definition from html builder.
     */
    protected function getColumnsFromBuilder(): Collection
    {
        return $this->html()->getColumns();
    }

    /**
     * Optional method if you want to use html builder.
     *
     * @return \Yajra\DataTables\Html\Builder
     */
    public function html()
    {
        return $this->builder();
    }

    /**
     * Get DataTables Html Builder instance.
     */
    public function builder(): Builder
    {
        if (method_exists($this, 'htmlBuilder')) {
            return $this->htmlBuilder = $this->htmlBuilder();
        }

        if (! $this->htmlBuilder) {
            $this->htmlBuilder = app(Builder::class);
        }

        return $this->htmlBuilder;
    }

    /**
     * Map ajax response to columns definition.
     */
    protected function mapResponseToColumns(array|\Illuminate\Support\Collection $columns, string $type): array
    {
        $transformer = new DataArrayTransformer;

        return array_map(fn ($row) => $transformer->transform($row, $columns, $type), $this->getAjaxResponseData());
    }

    /**
     * Get decorated data as defined in datatables ajax response.
     */
    protected function getAjaxResponseData(): array
    {
        $this->request()->merge([
            'start' => 0,
            'length' => -1,
        ]);

        /** @var JsonResponse $response */
        $response = app()->call($this->ajax(...));

        /** @var array{data: array} $data */
        $data = $response->getData(true);

        return $data['data'];
    }

    protected function getHtmlBuilder(): Builder
    {
        $builder = $this->html();
        if (is_callable($this->htmlCallback)) {
            app()->call($this->htmlCallback, compact('builder'));
        }

        return $builder;
    }

    /**
     * Add html builder callback hook.
     *
     * @return $this
     */
    public function withHtml(callable $callback): static
    {
        $this->htmlCallback = $callback;

        return $this;
    }

    /**
     * Add callback before sending the response.
     *
     * @return $this
     */
    public function before(callable $callback): static
    {
        $this->beforeCallback = $callback;

        return $this;
    }

    /**
     * Add callback after the response was processed.
     *
     * @return $this
     */
    public function response(callable $callback): static
    {
        $this->responseCallback = $callback;

        return $this;
    }

    /**
     * Export results to Excel file.
     *
     * @return string|\Symfony\Component\HttpFoundation\BinaryFileResponse|\Symfony\Component\HttpFoundation\StreamedResponse
     *
     * @throws \Exception
     */
    public function excel()
    {
        set_time_limit(3600);

        $path = $this->getFilename().'.'.strtolower($this->excelWriter);

        $excelFile = $this->buildExcelFile();

        if ($excelFile instanceof FastExcel) {
            $callback = $this->fastExcelCallback ? $this->fastExcelCallback() : null;

            return $excelFile->download($path, $callback);
        }

        // @phpstan-ignore-next-line
        return $excelFile->download($path, $this->excelWriter);
    }

    /**
     * Build Excel file and prepare for export.
     *
     * @return mixed|FastExcel
     *
     * @throws \Exception
     */
    protected function buildExcelFile()
    {
        if ($this->fastExcel) {
            return $this->buildFastExcelFile();
        }

        if (! class_exists(ExcelServiceProvider::class)) {
            throw new Exception('Please `composer require maatwebsite/excel` to be able to use this function.');
        }

        if (! new $this->exportClass instanceof DataTablesExportHandler) {
            $collection = $this->getAjaxResponseData();

            return new $this->exportClass($this->convertToLazyCollection($collection));
        }

        $collection = $this->getDataForExport();

        return new $this->exportClass($this->convertToLazyCollection($collection));
    }

    /**
     * Get export filename.
     */
    public function getFilename(): string
    {
        return $this->filename ?: $this->filename();
    }

    /**
     * Set export filename.
     *
     * @return $this
     */
    public function setFilename(string $filename): static
    {
        $this->filename = $filename;

        return $this;
    }

    /**
     * Get filename for export.
     */
    protected function filename(): string
    {
        return class_basename($this).'_'.date('YmdHis');
    }

    /**
     * Get mapped columns versus final decorated output.
     */
    protected function getDataForExport(): array
    {
        $columns = $this->exportColumns();

        return $this->mapResponseToColumns($columns, 'exportable');
    }

    /**
     * Get export columns definition.
     *
     * @return Collection<int, Column>
     */
    protected function exportColumns(): Collection
    {
        return is_array($this->exportColumns) ? $this->toColumnsCollection($this->exportColumns) : $this->getExportColumnsFromBuilder();
    }

    /**
     * Convert array to collection of Column class.
     */
    private function toColumnsCollection(array $columns): Collection
    {
        $collection = new Collection;

        foreach ($columns as $column) {
            if (isset($column['data'])) {
                $column['title'] ??= $column['data'];
                $collection->push(new Column($column));
            } else {
                $data = [];
                $data['data'] = $column;
                $data['title'] = $column;
                $collection->push(new Column($data));
            }
        }

        return $collection;
    }

    /**
     * Export results to CSV file.
     *
     * @return string|\Symfony\Component\HttpFoundation\StreamedResponse
     *
     * @throws \Exception
     */
    public function csv()
    {
        set_time_limit(3600);
        $path = $this->getFilename().'.'.strtolower($this->csvWriter);

        $excelFile = $this->buildExcelFile();

        if ($excelFile instanceof FastExcel) {
            $callback = $this->fastExcelCallback ? $this->fastExcelCallback() : null;

            return $excelFile->download($path, $callback);
        }

        // @phpstan-ignore-next-line
        return $this->buildExcelFile()->download($path, $this->csvWriter);
    }

    /**
     * Export results to PDF file.
     *
     * @return \Illuminate\Http\Response|string|\Symfony\Component\HttpFoundation\StreamedResponse
     *
     * @throws \Exception
     */
    public function pdf()
    {
        if (config('datatables-buttons.pdf_generator', 'snappy') == 'snappy') {
            return $this->snappyPdf();
        }

        // @phpstan-ignore-next-line
        return $this->buildExcelFile()->download($this->getFilename().'.pdf', $this->pdfWriter);
    }

    /**
     * PDF version of the table using print preview blade template.
     *
     *
     * @throws \Yajra\DataTables\Exceptions\Exception
     */
    public function snappyPdf(): Response
    {
        if (! class_exists(PdfWrapper::class)) {
            throw new Exception('Please `composer require barryvdh/laravel-snappy` to be able to use this feature.');
        }

        /** @var \Barryvdh\Snappy\PdfWrapper $snappy */
        $snappy = app('snappy.pdf.wrapper');
        $options = (array) config('datatables-buttons.snappy.options');

        /** @var string $orientation */
        $orientation = config('datatables-buttons.snappy.orientation');

        $snappy->setOptions($options)->setOrientation($orientation);

        return $snappy->loadHTML($this->printPreview())->download($this->getFilename().'.pdf');
    }

    /**
     * Add basic array query scopes.
     *
     * @return $this
     */
    public function addScope(DataTableScope $scope): static
    {
        $this->scopes[] = $scope;

        return $this;
    }

    /**
     * Push multiples scopes to array query scopes.
     *
     * @return $this
     */
    public function addScopes(array $scopes): static
    {
        $this->scopes = array_merge($this->scopes, $scopes);

        return $this;
    }

    /**
     * Set a custom class attribute.
     *
     * @return $this
     */
    public function with(array|string $key, mixed $value = null): static
    {
        if (is_array($key)) {
            $this->attributes = array_merge($this->attributes, $key);
        } else {
            $this->attributes[$key] = $value;
        }

        return $this;
    }

    /**
     * Dynamically retrieve the value of an attribute.
     *
     * @return mixed|null
     */
    public function __get(string $key)
    {
        if (array_key_exists($key, $this->attributes)) {
            return $this->attributes[$key];
        }

        return null;
    }

    /**
     * Apply query scopes.
     */
    protected function applyScopes(
        EloquentBuilder|QueryBuilder|EloquentRelation|Collection|AnonymousResourceCollection $query
    ): EloquentBuilder|QueryBuilder|EloquentRelation|Collection|AnonymousResourceCollection {
        foreach ($this->scopes as $scope) {
            $scope->apply($query);
        }

        return $query;
    }

    /**
     * Determine if the DataTable has scopes.
     */
    protected function hasScopes(array $scopes, bool $validateAll = false): bool
    {
        $filteredScopes = array_filter($this->scopes, fn ($scope) => in_array($scope::class, $scopes));

        return $validateAll ? count($filteredScopes) === count($scopes) : ! empty($filteredScopes);
    }

    /**
     * Get default builder parameters.
     */
    protected function getBuilderParameters(): array
    {
        /** @var array $defaults */
        $defaults = config('datatables-buttons.parameters', []);

        return $defaults;
    }

    protected function convertToLazyCollection(array|Collection $collection): LazyCollection
    {
        if (is_array($collection)) {
            $collection = collect($collection);
        }

        return $collection->lazy();
    }

    public function fastExcelCallback(): Closure
    {
        return function ($row) {
            $mapped = [];

            $this->exportColumns()->each(function (Column $column) use (&$mapped, $row) {
                if ($column['exportable']) {
                    $mapped[$column['title']] = $row[$column['data']];
                }
            });

            return $mapped;
        };
    }

    /**
     * @throws \Yajra\DataTables\Exceptions\Exception
     */
    protected function buildFastExcelFile(): FastExcel
    {
        if (! class_exists(FastExcel::class)) {
            throw new Exception('Please `composer require rap2hpoutre/fast-excel` to be able to use this function.');
        }

        $query = null;
        if (method_exists($this, 'query')) {
            /** @var EloquentBuilder|QueryBuilder $query */
            $query = app()->call([$this, 'query']);
            $query = $this->applyScopes($query);
        }

        /** @var \Yajra\DataTables\DataTableAbstract $dataTable */
        // @phpstan-ignore-next-line
        $dataTable = app()->call([$this, 'dataTable'], compact('query'));
        $dataTable->skipPaging();

        if ($dataTable instanceof QueryDataTable) {
            $queryGenerator = function ($dataTable): Generator {
                foreach ($dataTable->getFilteredQuery()->cursor() as $row) {
                    yield $row;
                }
            };

            return new FastExcel($queryGenerator($dataTable));
        }

        return new FastExcel($dataTable->toArray()['data']);
    }
}
