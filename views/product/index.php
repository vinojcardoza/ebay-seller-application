<?php

/**
 * View: Products List
 */

$this->layout('layout', ['title' => 'Products']);
?>
<h1>Products List</h1>

<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Title</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach($products as $product):?>
            <tr>
                <td><?php echo $product->getId();?></td>
                <td><?php echo $product->getTitle();?></td>
            </tr>
        <?php endforeach;?>
    </tbody>
</table>