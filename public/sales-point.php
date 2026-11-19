<?php
require __DIR__.'/../src/bootstrap.php';
$user=requireLogin();
$point=Store::find('sales_points',intField($_GET,'id')??0);
if($point===null)forbid('SALES_POINT_NOT_FOUND');
$canSeeStock=canAccessSalesPoint($user,(int)$point['id']);
// Người dùng điểm bán chỉ xem điểm bán được liên kết; tài khoản nội bộ xem được thông tin như ở danh sách điểm bán.
if($user['role']==='sales_user'&&!$canSeeStock)forbid('SALES_POINT_SCOPE');
$db=Database::connection();
$routeQuery=$db->prepare('SELECT warehouse_id,is_primary FROM sales_point_warehouses WHERE sales_point_id=:id ORDER BY is_primary DESC,warehouse_id');
$routeQuery->execute(['id'=>$point['id']]);$routes=$routeQuery->fetchAll();
$scope=array_column(allowedWarehouses($user),'id');
$orders=array_reverse(array_values(array_filter(Store::all('sales_orders'),
    fn($o)=>$o['sales_point_id']===$point['id']&&(in_array($o['warehouse_id'],$scope,true)||$canSeeStock))));
$balances=$canSeeStock?StockLedger::bySalesPoint(Store::all('stock_movements'),(int)$point['id']):[];
$items=Store::indexed('items');$batches=Store::indexed('batches');
$linkedUsers=[];
if(canManageCatalog($user)){$uq=$db->prepare('SELECT user_id FROM user_sales_points WHERE sales_point_id=:id ORDER BY user_id');$uq->execute(['id'=>$point['id']]);$linkedUsers=array_map('intval',$uq->fetchAll(PDO::FETCH_COLUMN));}
$title=$point['name'];$active='sales-points';require __DIR__.'/../templates/header.php';
?>
<nav class="breadcrumb"><a href="/sales-points.php">Điểm bán</a> / <?=e($point['code'])?></nav>
<header class="page-head"><div><h1><?=e($point['name'])?> <span class="badge"><?=e(SALES_POINT_TYPES[$point['point_type']]??$point['point_type'])?></span></h1><p><?=e($point['code'])?> · <?=$point['active']?'Đang hoạt động':'Ngừng hoạt động'?></p></div><?php if(canManageCatalog($user)):?><a class="button button--ghost" href="/sales-points.php?edit=<?=$point['id']?>">Sửa điểm bán</a><?php endif;?></header>
<section class="card"><dl class="meta">
<div><dt>Địa chỉ</dt><dd><?=e($point['address']?:'—')?></dd></div>
<div><dt>Người liên hệ</dt><dd><?=e($point['contact_name']?:'—')?></dd></div>
<div><dt>Điện thoại</dt><dd><?=e($point['phone']?:'—')?></dd></div>
<div><dt>Email</dt><dd><?=e($point['email']?:'—')?></dd></div>
<div><dt>Kho giao hàng</dt><dd><?php foreach($routes as $i=>$r):?><?=$i?'<br>':''?><?=e(warehouseName((int)$r['warehouse_id']))?><?=in_array($r['is_primary'],[true,1,'1','t','true'],true)?' <span class="muted">(chính)</span>':''?><?php endforeach;?><?=!$routes?'—':''?></dd></div>
<div><dt>Ngày tạo</dt><dd><?=e(dateOnly($point['created_at']??null))?></dd></div>
<?php if(canManageCatalog($user)):?><div><dt>Tài khoản liên kết</dt><dd><?=$linkedUsers?e(implode(', ',array_map('userName',$linkedUsers))):'Chưa có'?></dd></div><?php endif;?>
<div><dt>Ghi chú</dt><dd><?=e($point['note']?:'—')?></dd></div>
</dl></section>
<section class="card"><h2>Đơn phân phối</h2><?php if(!$orders):?><p class="empty">Chưa có đơn phân phối nào trong phạm vi của bạn.</p><?php else:?><div class="table-wrap"><table class="table"><thead><tr><th>Mã</th><th>Kho</th><th>Trạng thái</th><th>Ngày tạo</th></tr></thead><tbody><?php foreach($orders as $o):?><tr><td><a class="text-link" href="/sales-order.php?id=<?=$o['id']?>"><?=e($o['code'])?></a></td><td><?=e(warehouseName($o['warehouse_id']))?></td><td><?=salesStatusBadge($o['status'])?></td><td><?=e(dateOnly($o['created_at']))?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<?php if($canSeeStock):?><section class="card"><h2>Tồn tại điểm bán</h2><?php $rows=array_filter($balances,fn($q)=>$q!==0);if(!$rows):?><p class="empty">Điểm bán chưa có tồn.</p><?php else:?><div class="table-wrap"><table class="table"><thead><tr><th>Mặt hàng</th><th>Lô</th><th class="num">Tồn</th></tr></thead><tbody><?php foreach($rows as $key=>$qty):[, $batch]=array_map('intval',explode(':',$key));$b=$batches[$batch]??null;?><tr><td><?=e($b?($items[$b['item_id']]['name']??'—'):'—')?></td><td><?=e($b['code']??'—')?></td><td class="num"><?=qty($qty)?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?><p><a class="text-link" href="/sales-stock.php?sales_point_id=<?=$point['id']?>">Mở trang tồn điểm bán →</a></p></section><?php endif;?>
<?php require __DIR__.'/../templates/footer.php';
