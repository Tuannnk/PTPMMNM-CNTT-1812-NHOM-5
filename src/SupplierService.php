<?php
declare(strict_types=1);

final class SupplierService
{
    public static function save(array $actor, array $data, ?int $id = null): int
    {
        if (!canManageCatalog($actor)) throw new DomainException('SUPPLIER_FORBIDDEN');
        $code = strtoupper(trim((string)($data['code'] ?? '')));
        $name = trim((string)($data['company_name'] ?? ''));
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,39}$/', $code) || $name === '') {
            throw new DomainException('SUPPLIER_INVALID');
        }
        $db = Database::connection();
        $params = ['code'=>$code,'company_name'=>$name,'tax_code'=>trim((string)($data['tax_code']??'')),
            'contact_name'=>trim((string)($data['contact_name']??'')),'phone'=>trim((string)($data['phone']??'')),
            'email'=>trim((string)($data['email']??'')),'address'=>trim((string)($data['address']??'')),
            'active'=>!empty($data['active'])?'true':'false','note'=>trim((string)($data['note']??''))];
        if ($id === null) {
            $q=$db->prepare('INSERT INTO suppliers (code,company_name,tax_code,contact_name,phone,email,address,active,note) VALUES (:code,:company_name,:tax_code,:contact_name,:phone,:email,:address,:active,:note) RETURNING id');
        } else {
            $params['id']=$id;
            $q=$db->prepare('UPDATE suppliers SET code=:code,company_name=:company_name,tax_code=:tax_code,contact_name=:contact_name,phone=:phone,email=:email,address=:address,active=:active,note=:note,updated_at=now() WHERE id=:id RETURNING id');
        }
        $q->execute($params); $saved=$q->fetchColumn();
        if ($saved === false) throw new DomainException('SUPPLIER_NOT_FOUND');
        return (int)$saved;
    }
}
