<?php
require __DIR__.'/../src/bootstrap.php';
$user=requireLogin();
$points=Store::all('sales_points');
$allowed=array_values(array_filter($points,fn($p)=>canAccessSalesPoint($user,$p['id'])));
$selected=intField($_GET,'sales_point_id');
if($selected!==null&&!array_filter($allowed,fn($p)=>$p['id']===$selected))forbid('SALES_POINT_SCOPE');
$balances=StockLedger::bySalesPoint(Store::all('stock_movements'),$selected);
$items=Store::indexed('items');$batches=Store::indexed('batches');$pointsById=Store::indexed('sales_points');
$title='Tồn điểm bán';$active='sales-stock';require __DIR__.'/../templates/header.php';
?>
<header class="page-head"><div><h1>Tồn điểm bán</h1><p>Số tồn được tính từ các dòng giao nhận đã ghi sổ tại điểm bán.</p></div></header>
<form class="filters" method="get"><label>Điểm bán<select name="sales_point_id"><option value="">Tất cả điểm bán trong phạm vi</option><?php foreach($allowed as $p):?><option value="<?=$p['id']?>" <?=$selected===$p['id']?'selected':''?>><?=e($p['code'].' · '.$p['name'])?></option><?php endforeach;?></select></label><button class="button">Lọc</button></form>
<section class="card table-wrap"><table class="table"><thead><tr><th>Điểm bán</th><th>Mặt hàng</th><th>Lô</th><th class="num">Tồn</th></tr></thead><tbody><?php foreach($balances as $key=>$qty):[$point,$batch]=array_map('intval',explode(':',$key));if($qty===0)continue;$b=$batches[$batch];?><tr><td><?=e($points[$point]['name']??'—')?></td><td><?=e($items[$b['item_id']]['name']??'—')?></td><td><?=e($b['code'])?></td><td class="num"><?=qty($qty)?></td></tr><?php endforeach;?></tbody></table></section>
<?php require __DIR__.'/../templates/footer.php';
