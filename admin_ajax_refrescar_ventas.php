<?php
/* admin_ajax_refrescar_ventas.php - MOTOR FINAL SINCRONIZADO */
require '../includes/config.php';

session_start();
if (!isset($_SESSION['usuario_rol'])) die(json_encode(['error' => 'Acceso denegado']));

$estado = $_GET['estado'] ?? 'pendiente';
$sorteo_id = $_GET['sorteo'] ?? '';

// 1. Contadores
$sql_count = "SELECT COUNT(v.id) FROM ventas v JOIN sorteos s ON v.sorteo_id = s.id WHERE v.estado_pago = ? AND s.estado = 'activo'";
if($sorteo_id) $sql_count .= " AND v.sorteo_id = ?";

$stmt = $conexion->prepare($sql_count);
$stmt->execute($sorteo_id ? ['pendiente', $sorteo_id] : ['pendiente']);
$c_pend = $stmt->fetchColumn();

$stmt->execute($sorteo_id ? ['pagado', $sorteo_id] : ['pagado']);
$c_pag = $stmt->fetchColumn();

// 2. Tabla
$sql = "SELECT v.*, s.nombre as nombre_sorteo, s.precio_numero, 
        (SELECT GROUP_CONCAT(numero_boleto SEPARATOR ', ') FROM numeros WHERE venta_id = v.id) as lista_numeros
        FROM ventas v 
        JOIN sorteos s ON v.sorteo_id = s.id 
        WHERE v.estado_pago = :estado";

if($sorteo_id) $sql .= " AND v.sorteo_id = :sorteo";
$sql .= " ORDER BY v.fecha_venta DESC LIMIT 50";

$stmt = $conexion->prepare($sql);
$stmt->bindValue(':estado', $estado);
if($sorteo_id) $stmt->bindValue(':sorteo', $sorteo_id);
$stmt->execute();
$ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();

if (empty($ventas)) {
    echo '<tr><td colspan="6" style="text-align:center; padding:40px; color:#999;">Sin datos recientes.</td></tr>';
} else {
    // 1. CARGAR PLANTILLAS REALES DE LA BD
    $stmt_tpl = $conexion->query("SELECT nombre_plantilla, contenido FROM plantillas_mensajes");
    $templates = $stmt_tpl->fetchAll(PDO::FETCH_KEY_PAIR);

    $tpl_base_cobro   = $templates['Recordatorio Pago'] ?? "Hola [NOMBRE_CLIENTE], recuerda pagar [LISTA_NUMEROS].";
    $tpl_base_alerta  = $templates['Ultima Alerta'] ?? "URGENTE [NOMBRE_CLIENTE]: Tus números serán liberados.";
    $tpl_base_confirm = $templates['Confirmacion Pago'] ?? "✅ PAGO RECIBIDO [NOMBRE_CLIENTE]. Ticket: [LINK_TICKET]";

    foreach ($ventas as $row) {
        // 2. Datos Completos
        $datos_fila = [
            '[NOMBRE_CLIENTE]' => $row['nombre_comprador'],
            '[NOMBRE_SORTEO]'  => $row['nombre_sorteo'],
            '[LISTA_NUMEROS]'  => $row['lista_numeros'],
            '[VALOR_TOTAL]'    => '$' . number_format($row['total_pagado']),
            '[CODIGO_UNICO]'   => $row['codigo_unico'],
            '[LINK_TICKET]'    => "https://sebasapoyando.com/ticket.php?id=" . $row['id']
        ];
        
        // 3. Reemplazo
        $txt_cobro = str_replace(array_keys($datos_fila), array_values($datos_fila), $tpl_base_cobro);
        $txt_alerta = str_replace(array_keys($datos_fila), array_values($datos_fila), $tpl_base_alerta);
        $txt_confirm = str_replace(array_keys($datos_fila), array_values($datos_fila), $tpl_base_confirm);
        
        $tel_clean = preg_replace('/[^0-9]/','',$row['whatsapp_comprador']);
        
        // 4. Links
        $link_cobro = "https://wa.me/" . $tel_clean . "?text=" . urlencode($txt_cobro);
        $link_alerta = "https://wa.me/" . $tel_clean . "?text=" . urlencode($txt_alerta);
        $link_confirm = "https://wa.me/" . $tel_clean . "?text=" . urlencode($txt_confirm);
        
        $safe_nombre = htmlspecialchars($row['nombre_comprador'], ENT_QUOTES);
        ?>
        <tr class="fila-venta animacion-entrada" data-texto="<?php echo strtolower($row['codigo_unico'].' '.$row['nombre_comprador'].' '.$row['whatsapp_comprador']); ?>">
            <td style="text-align:center;"><input type="checkbox" class="venta-checkbox" value="<?php echo $row['id']; ?>"></td>
            <td data-label="Ticket" class="td-info">
                <div class="contenido-principal">
                    <span class="badge-gris"><?php echo $row['codigo_unico']; ?></span>
                    <div style="font-size:11px; color:#888; margin-top:4px;">
                        <i class="fa-regular fa-clock"></i> <?php echo date('h:i A', strtotime($row['fecha_venta'])); ?>
                    </div>
                </div>
            </td>
            <td class="td-info">
                <div class="contenido-principal">
                    <strong><?php echo htmlspecialchars($row['nombre_comprador']); ?></strong><br>
                    <div style="display:flex; align-items:center; gap:5px;">
                        <a href="https://wa.me/<?php echo $tel_clean; ?>" target="_blank" style="color:#28a745; font-size:12px;">
                            <i class="fa-brands fa-whatsapp"></i> <?php echo htmlspecialchars($row['whatsapp_comprador']); ?>
                        </a>
                        <button type="button" onclick="abrirModalTelefono('<?php echo $row['id']; ?>', '<?php echo $row['whatsapp_comprador']; ?>', '<?php echo $safe_nombre; ?>')" style="border:none; background:none; cursor:pointer; color:#666; font-size:12px;" title="Editar">
                            <i class="fa-solid fa-pencil"></i>
                        </button>
                    </div>
                </div>
            </td>
            <td class="td-info">
                <div class="contenido-principal">
                    <span style="font-size:13px;"><?php echo htmlspecialchars($row['nombre_sorteo']); ?></span><br>
                    <small style="color:#0056b3;"><i class="fa-solid fa-ticket"></i> <?php echo $row['lista_numeros']; ?></small>
                </div>
            </td>
            <td data-label="Total"><strong style="color:var(--color-principal);">$<?php echo number_format($row['total_pagado']); ?></strong></td>
            <td data-label="Acciones" style="text-align:center;">
                <div class="acciones-btns">
                    <a href="../ticket.php?id=<?php echo $row['id']; ?>" target="_blank" class="btn-accion" style="color:#555; border-color:#ddd;" title="Ticket"><i class="fa-solid fa-receipt"></i></a>
                    
                    <?php if($estado == 'pendiente'): ?>
                        <button type="button" class="btn-accion" onclick="abrirModalIndividual('<?php echo $row['id']; ?>', 'aprobar')" style="color:#28a745; border-color:#28a745;" title="Confirmar (Auto)"><i class="fa-solid fa-check"></i></button>
                        
                        <button type="button" class="btn-accion" onclick="abrirModalIndividual('<?php echo $row['id']; ?>', 'recordar')" style="color:#ffc107; border-color:#ffc107;" title="Recordar (Auto)"><i class="fa-regular fa-bell"></i></button>
                        
                        <button type="button" class="btn-accion" 
                                onclick="abrirMenuManual('<?php echo $row['id']; ?>', '<?php echo $safe_nombre; ?>', '<?php echo $link_cobro; ?>', '<?php echo $link_alerta; ?>', '<?php echo $link_confirm; ?>')" 
                                style="color:#6f42c1; border-color:#6f42c1; background:#f3e8ff;" 
                                title="Chat Manual">
                            <i class="fa-brands fa-whatsapp"></i>
                        </button>

                        <button type="button" class="btn-accion" onclick="abrirModalIndividual('<?php echo $row['id']; ?>', 'ultima_alerta')" style="color:#fd7e14; border-color:#fd7e14;" title="Ultimátum (Auto)"><i class="fa-solid fa-triangle-exclamation"></i></button>
                        
                    <?php endif; ?>
                    
                    <button type="button" class="btn-accion" onclick="abrirModalIndividual('<?php echo $row['id']; ?>', 'eliminar')" style="color:#dc3545; border-color:#dc3545;"><i class="fa-solid fa-trash"></i></button>
                </div>
            </td>
        </tr>
        <?php
    }
}
$html_filas = ob_get_clean();

echo json_encode(['html' => $html_filas, 'pendientes' => $c_pend, 'pagados' => $c_pag]);
?>