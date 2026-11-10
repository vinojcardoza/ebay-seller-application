<?php

/**
 * View: Edit purchase form, with delete.
 *
 * @var \League\Plates\Template\Template $this
 * @var \App\Entities\Purchase $purchase
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var array<int, string> $suppliers
 * @var array<string, array<int, string>> $productGroups
 * @var array<int, string> $statuses
 */

$this->layout('layout', [
    'title' => 'Edit purchase #' . $purchase->getId(),
    'breadcrumbs' => ['Purchases' => '/purchases', 'Edit #' . $purchase->getId() => null],
]);
?>
<div class="row">
    <div class="col-xl-8">
        <?php $this->insert('purchases/_form', [
            'action' => '/purchases/' . $purchase->getId(),
            'submitLabel' => 'Save changes',
            'values' => $values,
            'errors' => $errors,
            'suppliers' => $suppliers,
            'productGroups' => $productGroups,
            'statuses' => $statuses,
        ]) ?>

        <div class="card">
            <div class="card-header">
                <h2>Delete purchase</h2>
            </div>
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                <p class="mb-0">Removing a delivered purchase changes the stock used to cost future orders.</p>
                <form method="post" action="/purchases/<?php echo $purchase->getId(); ?>/delete"
                      data-confirm="Delete this purchase?">
                    <button type="submit" class="btn btn-light-danger">
                        <i class="ti ti-trash" aria-hidden="true"></i> Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
