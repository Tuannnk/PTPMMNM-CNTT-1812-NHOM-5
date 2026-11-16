<?php
declare(strict_types=1);

final class SalesPointService
{
    public static function save(array $actor, array $data, ?int $id = null): int
    {
        if (!canManageCatalog($actor)) throw new DomainException('SALES_POINT_FORBIDDEN');
        $code=strtoupper(trim((string)($data['code']??''))); $name=trim((string)($data['name']??''));
        $type=(string)($data['point_type']??''); $warehouse=(int)($data['primary_warehouse_id']??0);
        $warehouseRecord=Store::find('warehouses',$warehouse);
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,39}$/',$code)||$name===''||!in_array($type,['AGENT','STORE','SUPERMARKET'],true)
            ||!$warehouseRecord||!$warehouseRecord['active']||($actor['role']!=='admin'&&!canAccessWarehouse($actor,$warehouse))) throw new DomainException('SALES_POINT_INVALID');
        $db=Database::connection();
        $db->beginTransaction();
        try {
            $p=['code'=>$code,'name'=>$name,'point_type'=>$type,'address'=>trim((string)($data['address']??'')),'contact_name'=>trim((string)($data['contact_name']??'')),'phone'=>trim((string)($data['phone']??'')),'email'=>trim((string)($data['email']??'')),'warehouse'=>$warehouse,'active'=>!empty($data['active'])?'true':'false','note'=>trim((string)($data['note']??''))];
            if($id===null){$q=$db->prepare('INSERT INTO sales_points (code,name,point_type,address,contact_name,phone,email,primary_warehouse_id,active,note) VALUES (:code,:name,:point_type,:address,:contact_name,:phone,:email,:warehouse,:active,:note) RETURNING id');}
            else{$p['id']=$id;$q=$db->prepare('UPDATE sales_points SET code=:code,name=:name,point_type=:point_type,address=:address,contact_name=:contact_name,phone=:phone,email=:email,primary_warehouse_id=:warehouse,active=:active,note=:note WHERE id=:id RETURNING id');}
            $q->execute($p);$saved=$q->fetchColumn();if($saved===false)throw new DomainException('SALES_POINT_NOT_FOUND');
            // This form supports one responsible warehouse; replace old route mappings.
            $db->prepare('DELETE FROM sales_point_warehouses WHERE sales_point_id=:point')->execute(['point'=>$saved]);
            $link=$db->prepare('INSERT INTO sales_point_warehouses (sales_point_id,warehouse_id,is_primary) VALUES (:point,:warehouse,true)');
            $link->execute(['point'=>$saved,'warehouse'=>$warehouse]);
            $db->commit();
            return (int)$saved;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
