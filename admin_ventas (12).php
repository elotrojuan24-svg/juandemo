<?php
/* admin/admin_ventas.php - VERSIÓN PLOMO (CORREGIDA Y FUNCIONAL) */

// 1. Configuración de errores para ver qué pasa si falla (en lugar de pantalla blanca)
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// 2. CARGA ROBUSTA DE ARCHIVOS
require '../includes/admin_header.php'; 

// Aseguramos la conexión y librerías
$ruta_includes = __DIR__ . '/../includes/';
if(!isset($conexion)) {
    if(file_exists($ruta_includes . 'config.php')) require_once $ruta_includes . 'config.php';
}

if(file_exists($ruta_includes . 'wamundo.php')) require_once $ruta_includes . 'wamundo.php';
// (Funciones.php lo saltamos o cargamos con cuidado, porque vamos a parchar aquí mismo)
if(file_exists($ruta_includes . 'funciones.php')) @include_once $ruta_includes . 'funciones.php';

// =============================================================================
// 🔧 EL PARCHE MAESTRO (Para que Wamundo y Plantillas funcionen con tu DB real)
// =============================================================================

// Parche 1: Configuración (Para que Wamundo encuentre las claves)
if (!function_exists('obtenerConfig')) {
    function obtenerConfig($key) {
        global $conexion;
        try {
            // Tu tabla usa 'clave' y 'valor'
            $stmt = $conexion->prepare("SELECT valor FROM configuracion WHERE clave = ? LIMIT 1");
            $stmt->execute([$key]);
            return $stmt->fetchColumn() ?: '';
        } catch (Exception $e) { return ''; }
    }
}

// Parche 2: Plantillas (Para que encuentre los textos)
if (!function_exists('obtenerPlantilla')) {
    function obtenerPlantilla($nombre, $datos, $conn) {
        try {
            // Tu tabla es 'plantillas_mensajes' y la columna 'nombre_plantilla'
            $stmt = $conn->prepare("SELECT contenido FROM plantillas_mensajes WHERE nombre_plantilla = ? LIMIT 1");
            $stmt->execute([$nombre]);
            $mensaje = $stmt->fetchColumn();
            
            if (!$mensaje) return false;
            
            foreach ($datos as $k => $v) {
                $mensaje = str_replace("[$k]", $v, $mensaje);
            }
            return $mensaje;
        } catch (Exception $e) { return false; }
    }
}
// =============================================================================

// --- FILTROS (Estado y Sorteo) ---
$filtro_estado = $_GET['estado'] ?? 'pendiente'; 
$filtro_sorteo = $_GET['sorteo'] ?? ''; // Variable para el nuevo filtro

// Obtener lista de todos los sorteos para el menú desplegable
$lista_sorteos = [];
try {
    $lista_sorteos = $conexion->query("SELECT id, nombre FROM sorteos ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

$mensaje_exito = null;
$mensaje_error = null;

// --- PROCESAMIENTO DE ACCIONES ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $ids_procesar = [];
    $accion = '';

    if (isset($_POST['venta_id']) && !empty($_POST['venta_id'])) {
        $ids_procesar[] = $_POST['venta_id'];
        $accion = $_POST['accion_venta'];
    } elseif (isset($_POST['ids_masivos']) && !empty($_POST['ids_masivos'])) {
        $ids_procesar = explode(',', $_POST['ids_masivos']);
        $accion = $_POST['accion_masiva'];
    }

    if (!empty($ids_procesar)) {
        try {
            if (!isset($conexion)) throw new Exception("Error de conexión a BD.");
            
            $conexion->beginTransaction();
            
            // Consultas Preparadas
            $sql_info = "SELECT v.*, s.nombre as snom, s.precio_numero FROM ventas v JOIN sorteos s ON v.sorteo_id = s.id WHERE v.id = ?";
            $stmt_info = $conexion->prepare($sql_info);
            
            $sql_nums = "SELECT numero_boleto FROM numeros WHERE venta_id = ?";
            $stmt_nums = $conexion->prepare($sql_nums);
            
            $sql_upd_pago = $conexion->prepare("UPDATE ventas SET estado_pago='pagado' WHERE id=?");
            $sql_upd_nums = $conexion->prepare("UPDATE numeros SET estado='vendido' WHERE venta_id=?");
            $sql_liberar_nums = $conexion->prepare("UPDATE numeros SET estado='disponible', venta_id=NULL WHERE venta_id=?");
            $sql_borrar_venta = $conexion->prepare("DELETE FROM ventas WHERE id=?");
            
            // Detector de premios
            $stmt_sorpresa = $conexion->prepare("SELECT numero_boleto, premio_sorpresa FROM numeros WHERE venta_id = ? AND es_sorpresa = 1");

            foreach ($ids_procesar as $id_v) {
                $stmt_info->execute([$id_v]);
                $v_info = $stmt_info->fetch(PDO::FETCH_ASSOC);
                
                if($v_info) {
                    // Datos básicos para mensajes
                    $stmt_nums->execute([$id_v]);
                    $nums_str = implode(", ", $stmt_nums->fetchAll(PDO::FETCH_COLUMN));
                    
                    $datos_plantilla = [
                        'NOMBRE_CLIENTE' => $v_info['nombre_comprador'],
                        'NOMBRE_SORTEO' => $v_info['snom'],
                        'LISTA_NUMEROS' => $nums_str,
                        'VALOR_TOTAL' => '$' . number_format($v_info['total_pagado']),
                        'CODIGO_UNICO' => $v_info['codigo_unico'],
                        'LINK_TICKET' => "https://sebasapoyando.com/ticket.php?id=" . $id_v
                    ];

                    // --- ACCIÓN: APROBAR ---
                    if ($accion == 'aprobar') {
                        $sql_upd_pago->execute([$id_v]);
                        $sql_upd_nums->execute([$id_v]);
                        
                        // Enviar WhatsApp Confirmación
                        $msj = obtenerPlantilla('Confirmacion Pago', $datos_plantilla, $conexion);
                        
                        // Fallback Manual
                        if(!$msj) {
                            $msj = "✅ *PAGO CONFIRMADO*\nHola {$v_info['nombre_comprador']}, ya tienes tus números para {$v_info['snom']}.\nTicket: {$v_info['codigo_unico']}\nDescargar: " . $datos_plantilla['LINK_TICKET'];
                        }

                        if(function_exists('enviarMensajeWamundo')) {
                            // Usamos @ para suprimir errores visuales si falla la API
                            @enviarMensajeWamundo($v_info['whatsapp_comprador'], $msj, $conexion);
                        }

                        // Premios Sorpresa
                        $stmt_sorpresa->execute([$id_v]);
                        $premios = $stmt_sorpresa->fetchAll(PDO::FETCH_ASSOC);
                        foreach($premios as $p) {
                            $txt_premio = "🏆 *¡PREMIO SORPRESA!* 🏆\nEl número *{$p['numero_boleto']}* tiene premio: {$p['premio_sorpresa']}";
                            if(function_exists('enviarMensajeWamundo')) {
                                @enviarMensajeWamundo($v_info['whatsapp_comprador'], $txt_premio, $conexion);
                            }
                        }
                    } 
                    
                    // --- ACCIÓN: RECORDAR (Cobrar) ---
                    elseif ($accion == 'recordar') {
                        // Intentar plantilla
                        $msj = obtenerPlantilla('Recordatorio Pago', $datos_plantilla, $conexion);
                        
                        // Fallback Manual
                        if(!$msj) {
                            $msj = "⏳ *RECORDATORIO*\nHola {$v_info['nombre_comprador']}, recuerda pagar tu reserva de números: $nums_str.\nTotal: \${$datos_plantilla['VALOR_TOTAL']}\nLink: " . $datos_plantilla['LINK_TICKET'];
                        }

                        if(function_exists('enviarMensajeWamundo')) {
                            @enviarMensajeWamundo($v_info['whatsapp_comprador'], $msj, $conexion);
                        }
                    } 
                    // ... (Debajo del bloque de 'recordar') ...
                    
                    // --- NUEVA ACCIÓN: ÚLTIMA ALERTA ---
                    elseif ($accion == 'ultima_alerta') {
                        // Intentar obtener plantilla de BD
                        $msj = obtenerPlantilla('Ultima Alerta', $datos_plantilla, $conexion);
                        
                        // Fallback Manual (Por si borran la plantilla)
                        if(!$msj) {
                            $msj = "⚠️ *ÚLTIMO AVISO*\nHola {$v_info['nombre_comprador']}, tus números $nums_str están por ser liberados por falta de pago.\nTotal: \${$datos_plantilla['VALOR_TOTAL']}\nLink: " . $datos_plantilla['LINK_TICKET'];
                        }

                        if(function_exists('enviarMensajeWamundo')) {
                            @enviarMensajeWamundo($v_info['whatsapp_comprador'], $msj, $conexion);
                        }
                    }
                    
                    // --- ACCIÓN: ELIMINAR ---
                    elseif ($accion == 'eliminar') {
                        $sql_liberar_nums->execute([$id_v]);
                        $sql_borrar_venta->execute([$id_v]);
                    }
                }
            }
            
            $conexion->commit();
            $mensaje_exito = "¡Proceso completado correctamente!";
            
        } catch (Exception $e) {
            if(isset($conexion) && $conexion->inTransaction()) $conexion->rollBack();
            $mensaje_error = "Error del sistema: " . $e->getMessage();
        }
    }
}

// Consultas para la vista (Contadores y Tabla con Filtros)
try {
   // 1. Contadores (Solo sorteos ACTIVOS)
    $sql_base_count = "SELECT COUNT(v.id) FROM ventas v 
                       JOIN sorteos s ON v.sorteo_id = s.id 
                       WHERE v.estado_pago = :est AND s.estado = 'activo'";
    
    if($filtro_sorteo) $sql_base_count .= " AND v.sorteo_id = :sid";
    
    // Contador Pendientes
    $stmt_c = $conexion->prepare($sql_base_count);
    $stmt_c->bindValue(':est', 'pendiente');
    if($filtro_sorteo) $stmt_c->bindValue(':sid', $filtro_sorteo);
    $stmt_c->execute();
    $c_pend = $stmt_c->fetchColumn();

    // Contador Pagados
    $stmt_c->bindValue(':est', 'pagado');
    $stmt_c->execute();
    $c_pag = $stmt_c->fetchColumn();

    // 2. Consulta Principal (La lista de ventas)
    $sql = "SELECT v.*, s.nombre as nombre_sorteo, 
            (SELECT GROUP_CONCAT(numero_boleto SEPARATOR ', ') FROM numeros WHERE venta_id = v.id) as lista_numeros
            FROM ventas v 
            JOIN sorteos s ON v.sorteo_id = s.id 
            WHERE v.estado_pago = :estado";
            
    // Si hay filtro de sorteo, agregamos esa condición
    if($filtro_sorteo) {
        $sql .= " AND v.sorteo_id = :sorteo";
    }
    
    $sql .= " ORDER BY v.fecha_venta DESC LIMIT 200";

    $stmt = $conexion->prepare($sql);
    $stmt->bindParam(':estado', $filtro_estado);
    if($filtro_sorteo) {
        $stmt->bindParam(':sorteo', $filtro_sorteo);
    }
    $stmt->execute();
    $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // ... (Tu código de consulta $sql actual) ...
    $stmt->execute();
    $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // --- 3. PRECARGA DE PLANTILLAS (NUEVO) ---
    // Traemos todas las plantillas de una vez para usarlas en el bucle
    $stmt_tpl = $conexion->query("SELECT nombre_plantilla, contenido FROM plantillas_mensajes");
    $templates = $stmt_tpl->fetchAll(PDO::FETCH_KEY_PAIR); // Crea array [Nombre => Contenido]

    // Definimos las 3 plantillas clave (con fallback por si se borraron)
    $tpl_base_cobro   = $templates['Recordatorio Pago'] ?? "Hola [NOMBRE_CLIENTE], recuerda pagar [LISTA_NUMEROS]. Total: [VALOR_TOTAL]";
    $tpl_base_alerta  = $templates['Ultima Alerta'] ?? "URGENTE [NOMBRE_CLIENTE]: Tus números [LISTA_NUMEROS] serán liberados.";
    $tpl_base_confirm = $templates['Confirmacion Pago'] ?? "✅ PAGO EXITOSO [NOMBRE_CLIENTE]. Ticket: [LINK_TICKET]";
    $ultimo_id_visible = 0;
if(!empty($ventas)) {
    $ultimo_id_visible = $ventas[0]['id']; // El primero es el más nuevo porque ordenas DESC
}

} catch (Exception $e) {
    $mensaje_error = "Error cargando tabla: " . $e->getMessage();
    $ventas = [];
}

?>

<style>
    /* 1. Estilos Checkbox */
    .venta-checkbox, #select-all { width: 20px !important; height: 20px !important; cursor: pointer; accent-color: var(--color-principal); display: block; }
    
    /* 2. Barra Flotante (Acciones Masivas) - PC */
    .barra-masiva {
        position: fixed; z-index: 9999; background: #1e1e1e; color: white;
        border-radius: 50px; box-shadow: 0 10px 30px rgba(0,0,0,0.4);
        display: flex; align-items: center; justify-content: space-between;
        gap: 15px; padding: 10px 20px;
        transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        bottom: 30px; left: 50%; width: auto; min-width: 400px; 
        transform: translateX(-50%) translateY(150%);
    }
    .barra-masiva.visible { transform: translateX(-50%) translateY(0); }

    .btn-masivo { border: none; padding: 8px 16px; border-radius: 30px; font-size: 13px; font-weight: 700; cursor: pointer; text-transform: uppercase; color: white; display: flex; align-items: center; gap: 6px; transition: 0.2s; white-space: nowrap; }
    .btn-masivo:active { transform: scale(0.95); }
    .btn-masivo.verde { background: #2ecc71; }
    .btn-masivo.amarillo { background: #f1c40f; color: #222; } 
    .btn-masivo.rojo { background: #e74c3c; }

    /* 3. Notificación Negra (Toast) - ESTILO BASE */
    .toast-notification {
        visibility: hidden;
        min-width: 250px;
        background-color: #333;
        color: #fff;
        text-align: center;
        border-radius: 50px;
        padding: 16px;
        position: fixed;
        z-index: 11000;
        left: 50%;
        bottom: 30px;
        transform: translateX(-50%);
        opacity: 0;
        transition: 0.3s;
        box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        font-size: 14px;
        pointer-events: none;
    }
    .toast-notification.show {
        visibility: visible;
        opacity: 1;
        bottom: 50px;
    }

    /* 4. Barra de Selección Móvil (Oculta en PC) */
    .mobile-select-bar { display: none; margin-bottom: 10px; padding: 15px; background: #fff; border-radius: 12px; border: 1px solid #eee; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }

    /* --- RESPONSIVE (CELULARES) --- */
    @media (max-width: 768px) {
        /* Barra de selección visible */
        .mobile-select-bar { display: block; }
        
        /* Ajuste Checkbox centrado */
        .venta-checkbox { margin: 0 auto; }

        /* Barra Masiva en Grid 2x2 */
        .barra-masiva {
            left: 15px !important; right: 15px !important; width: auto !important; min-width: 0 !important;
            bottom: 20px !important; border-radius: 20px !important; padding: 15px !important;
            transform: translateY(150%) !important; 
            flex-direction: column !important; align-items: stretch !important; gap: 12px !important;
        }
        .barra-masiva.visible { transform: translateY(0) !important; }
        
        .barra-masiva > div:first-child { width: 100%; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.15); padding-bottom: 8px; }
        .barra-masiva > div:last-child { display: grid !important; grid-template-columns: 1fr 1fr; gap: 8px !important; width: 100%; }
        .btn-masivo { justify-content: center !important; width: 100% !important; font-size: 11px !important; padding: 10px 5px !important; border-radius: 12px !important; }

        /* Ajuste Notificación (Toast) en Móvil */
        .toast-notification {
            left: 20px !important; right: 20px !important; width: auto !important; min-width: 0 !important;
            transform: none !important;
            bottom: 90px !important;
            border-radius: 12px !important;
            white-space: normal !important;
        }
        .toast-notification.show { bottom: 90px !important; }
    }
</style>

<div class="admin-container">
    <div class="admin-header-con-boton">
        <h1><i class="fa-solid fa-cash-register"></i> Gestión de Ventas</h1>
        <a href="index.php" class="btn-secundario"><i class="fa-solid fa-arrow-left"></i> Volver</a>
    </div>

  

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:20px;">
        
        <div onclick="window.location.href='?estado=pendiente&sorteo=<?php echo $filtro_sorteo; ?>'" style="<?php echo $filtro_estado=='pendiente' ? 'border:2px solid #dc3545; background:#fff5f5;' : 'border:1px solid #eee; background:#fff;'; ?> padding:15px; border-radius:12px; text-align:center; cursor:pointer;">
            <strong id="contador-pendientes" style="font-size:22px; color:#dc3545; display:block;">
                <?php echo $c_pend; ?>
            </strong>
            <span style="font-size:11px; color:#dc3545; font-weight:800;">PENDIENTES</span>
        </div>

        <div onclick="window.location.href='?estado=pagado&sorteo=<?php echo $filtro_sorteo; ?>'" style="<?php echo $filtro_estado=='pagado' ? 'border:2px solid #28a745; background:#f0fff4;' : 'border:1px solid #eee; background:#fff;'; ?> padding:15px; border-radius:12px; text-align:center; cursor:pointer;">
            <strong id="contador-pagados" style="font-size:22px; color:#28a745; display:block;">
                <?php echo $c_pag; ?>
            </strong>
            <span style="font-size:11px; color:#28a745; font-weight:800;">PAGADOS</span>
        </div>

    </div>

    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 15px;">
        
        <select class="buscador-admin" style="flex: 1; min-width: 200px; cursor: pointer; background-image: none;" 
                onchange="location.href='?estado=<?php echo $filtro_estado; ?>&sorteo='+this.value">
            <option value="">📂 Todos los Sorteos</option>
            <?php foreach($lista_sorteos as $s): ?>
                <option value="<?php echo $s['id']; ?>" <?php echo $filtro_sorteo == $s['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($s['nombre']); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div class="buscador-admin-contenedor" style="margin:0; flex: 2; min-width: 200px;">
            <i class="fa-solid fa-search icono-lupa"></i>
            <input type="text" id="buscador-ventas" class="buscador-admin" placeholder="Buscar cliente, ticket..." style="width:100%;">
            <button id="btn-borrar-busqueda" class="btn-limpiar-search" style="display:none;"><i class="fa-solid fa-times"></i></button>
        </div>
    </div>

    <div class="mobile-select-bar">
        <label style="display:flex; align-items:center; gap:10px; font-weight:600; color:#555; cursor:pointer; width: 100%;">
            <input type="checkbox" id="select-all-mobile" style="width:20px; height:20px; accent-color:var(--color-principal);">
            Seleccionar Todos
        </label>
    </div>

    <div class="table-responsive">
        <form id="form-acciones" method="POST">
            <input type="hidden" name="ids_masivos" id="input-ids-masivos">
            <input type="hidden" name="venta_id" id="input-id-individual">
            <input type="hidden" name="accion_venta" id="input-accion-individual">
            <input type="hidden" name="accion_masiva" id="input-accion-masiva">

            <table class="tabla-admin" id="tabla-ventas">
                <thead>
                    <tr>
                        <th width="30" style="text-align:center;"><input type="checkbox" id="select-all"></th>
                        <th>Ticket</th>
                        <th>Cliente</th>
                        <th>Sorteo / Números</th>
                        <th>Total</th>
                        <th style="text-align:center;">Acciones</th>
                    </tr>
                </thead>
                <tbody id="cuerpo-tabla">
                    <?php if (empty($ventas)): ?>
                        <tr><td colspan="6" style="text-align:center; padding:40px; color:#999;">Sin datos.</td></tr>
                    <?php else: ?>
                    
                        <?php foreach ($ventas as $row): ?>
                        <tr class="fila-venta" data-texto="<?php echo strtolower($row['codigo_unico'].' '.$row['nombre_comprador'].' '.$row['whatsapp_comprador']); ?>">
                            <td style="text-align:center;"><input type="checkbox" class="venta-checkbox" value="<?php echo $row['id']; ?>"></td>
                            <td data-label="Ticket" class="td-info">
                                <div class="contenido-principal">
                                     <span class="badge-gris"><?php echo $row['codigo_unico']; ?></span>
                                                             <div style="font-size:11px; color:#888; margin-top:4px;">
                                                        <i class="fa-regular fa-clock"></i> <?php echo date('h:i A', strtotime($row['fecha_venta'])); ?>
                                                    </div>
                                                </div>
                            <td class="td-info">
                                <div class="contenido-principal">
                                    <strong><?php echo htmlspecialchars($row['nombre_comprador']); ?></strong><br>
                                    
                                    <div style="display:flex; align-items:center; gap:5px;">
                                        <a href="https://wa.me/<?php echo $row['whatsapp_comprador']; ?>" target="_blank" style="color:#28a745; font-size:12px;">
                                            <i class="fa-brands fa-whatsapp"></i> <?php echo htmlspecialchars($row['whatsapp_comprador']); ?>
                                        </a>
                                        
                                        <button type="button" onclick="abrirModalTelefono('<?php echo $row['id']; ?>', '<?php echo $row['whatsapp_comprador']; ?>', '<?php echo htmlspecialchars($row['nombre_comprador']); ?>')" style="border:none; background:none; cursor:pointer; color:#666; font-size:12px;" title="Corregir Número">
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
        <a href="../ticket.php?id=<?php echo $row['id']; ?>" target="_blank" class="btn-accion" style="color:#555; border-color:#ddd;" title="Ver Ticket"><i class="fa-solid fa-receipt"></i></a>
        
        <?php if($filtro_estado == 'pendiente'): ?>
            
            <button type="button" class="btn-accion" onclick="abrirModalIndividual('<?php echo $row['id']; ?>', 'aprobar')" style="color:#28a745; border-color:#28a745;" title="Confirmar Pago"><i class="fa-solid fa-check"></i></button>
            
            <button type="button" class="btn-accion" onclick="abrirModalIndividual('<?php echo $row['id']; ?>', 'recordar')" style="color:#ffc107; border-color:#ffc107;" title="Recordatorio Automático"><i class="fa-regular fa-bell"></i></button>
       <?php
            // --- LÓGICA BOTÓN MANUAL (CON PLANTILLAS DB) ---
            
            // 1. Datos para reemplazo (Usamos las llaves de plantilla [NOMBRE])
            $datos_manual = [
                '[NOMBRE_CLIENTE]' => $row['nombre_comprador'],
                '[NOMBRE_SORTEO]'  => $row['nombre_sorteo'],
                '[LISTA_NUMEROS]'  => $row['lista_numeros'],
                '[VALOR_TOTAL]'    => '$' . number_format($row['total_pagado']),
                '[CODIGO_UNICO]'   => $row['codigo_unico'],
                '[LINK_TICKET]'    => "https://sebasapoyando.com/ticket.php?id=" . $row['id']
            ];

            // 2. Reemplazo de variables en las plantillas cargadas arriba ($tpl_base_...)
            $txt_c = str_replace(array_keys($datos_manual), array_values($datos_manual), $tpl_base_cobro);
            $txt_a = str_replace(array_keys($datos_manual), array_values($datos_manual), $tpl_base_alerta);
            $txt_p = str_replace(array_keys($datos_manual), array_values($datos_manual), $tpl_base_confirm);

            // 3. Limpieza y Links
            $tel_clean = preg_replace('/[^0-9]/', '', $row['whatsapp_comprador']);
            $nom_safe = htmlspecialchars($row['nombre_comprador'], ENT_QUOTES);
            
            $lk_c = "https://wa.me/$tel_clean?text=" . urlencode($txt_c);
            $lk_a = "https://wa.me/$tel_clean?text=" . urlencode($txt_a);
            $lk_p = "https://wa.me/$tel_clean?text=" . urlencode($txt_p);
            ?>
            
          <button type="button" class="btn-accion" 
                    style="color:#6f42c1; border-color:#6f42c1; background:#f3e8ff;" 
                    onclick="abrirMenuManual('<?php echo $nom_safe; ?>', '<?php echo $lk_c; ?>', '<?php echo $lk_a; ?>', '<?php echo $lk_p; ?>')"
                    title="Chat Manual">
                <i class="fa-brands fa-whatsapp"></i>
            </button>

            <button type="button" class="btn-accion" onclick="abrirModalIndividual('<?php echo $row['id']; ?>', 'ultima_alerta')" style="color:#fd7e14; border-color:#fd7e14;" title="Ultimátum Automático"><i class="fa-solid fa-triangle-exclamation"></i></button>
            
        <?php endif; ?>
        
        <button type="button" class="btn-accion" onclick="abrirModalIndividual('<?php echo $row['id']; ?>', 'eliminar')" style="color:#dc3545; border-color:#dc3545;"><i class="fa-solid fa-trash"></i></button>
    </div>
</td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </form>
    </div>
</div>

<div class="barra-masiva" id="barra-masiva">
    <div style="display:flex; align-items:center; gap:10px;">
        <button type="button" id="btn-cancelar-sel" style="background:none; border:none; color:#aaa; font-size:20px;"><i class="fa-solid fa-times"></i></button>
        <span id="contador-masivo" style="font-weight:600;">0 sel.</span>
    </div>
    <div style="display:flex; gap:8px;">
        <?php if($filtro_estado == 'pendiente'): ?>
            <button type="button" class="btn-masivo verde" onclick="prepararMasivo('aprobar')">Aprobar</button>
            <button type="button" class="btn-masivo amarillo" onclick="prepararMasivo('recordar')">Cobrar</button>
            
            <button type="button" class="btn-masivo" style="background:#fd7e14;" onclick="prepararMasivo('ultima_alerta')">Ultimátum</button>
        <?php endif; ?>
        <button type="button" class="btn-masivo rojo" onclick="prepararMasivo('eliminar')">Borrar</button>
    </div>
</div>

<div class="modal-overlay" id="modal-confirmar">
    <div class="modal-content">
        <div class="modal-header"><h3>Confirmación</h3></div>
        <div class="modal-body"><p id="texto-confirmacion">¿Proceder?</p></div>
        <div class="modal-footer">
            <button class="btn-secundario" id="btn-cancelar-modal">Cancelar</button>
            <button class="btn-principal" id="btn-ejecutar-modal">Confirmar</button>
        </div>
    </div>
</div>
<div class="modal-overlay" id="modal-editar-tel">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-user-pen"></i> Editar Datos Cliente</h3>
        </div>
        <div class="modal-body">
            <form id="form-editar-tel" onsubmit="event.preventDefault(); guardarNuevoDatos();">
                <input type="hidden" id="edit-id-venta">
                <div class="input-group">
                    <label>Nombre del Cliente</label>
                    <input type="text" id="edit-nuevo-nombre" class="buscador-admin" style="width:100%; margin-bottom:10px;" placeholder="Nombre completo">
                </div>
                <div class="input-group">
                    <label>WhatsApp (Sin espacios)</label>
                    <input type="tel" id="edit-nuevo-numero" class="buscador-admin" style="width:100%; font-size:18px; text-align:center; letter-spacing:1px;" placeholder="573001234567">
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-secundario" onclick="cerrarModalTel()">Cancelar</button>
            <button type="button" class="btn-principal" onclick="guardarNuevoDatos()">Guardar Cambios</button>
        </div>
    </div>
</div>

<div id="toast" class="toast-notification"></div>
<style>
    /* --- ESTILO DE NOTIFICACIÓN UNIFICADO (PC + MÓVIL) --- */
    .toast-notification {
        visibility: hidden;
        min-width: 250px;
        background-color: #333;
        color: #fff;
        text-align: center;
        border-radius: 50px;
        padding: 16px;
        position: fixed;
        z-index: 11000; /* Capa muy alta para estar sobre todo */
        left: 50%;
        bottom: 30px; /* SIEMPRE ABAJO */
        transform: translateX(-50%); /* Centrado matemático en PC */
        opacity: 0;
        transition: 0.3s;
        box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        font-size: 14px;
    }

    .toast-notification.show {
        visibility: visible;
        opacity: 1;
        bottom: 50px; /* Pequeña animación hacia arriba */
    }

    /* --- CORRECCIÓN QUIRÚRGICA MÓVIL --- */
    @media (max-width: 768px) {
        .toast-notification {
            /* 1. ANCLAJES: Lo obligamos a quedarse dentro de la pantalla */
            left: 20px !important;
            right: 20px !important;
            width: auto !important;
            margin: 0 !important;
            
            /* 2. RESET: Anulamos el movimiento del diseño de PC */
            transform: none !important; 
            min-width: 0 !important; 

            /* 3. POSICIÓN Y ESTILO */
            bottom: 90px !important; /* Un poco más arriba para no chocar con menús del navegador */
            border-radius: 12px !important;
            padding: 15px !important;
            
            /* 4. TEXTO */
            white-space: normal !important; 
        }
        
        /* --- ESTILO DE NOTIFICACIÓN DEFINITIVO --- */
    .toast-notification {
        visibility: hidden;
        min-width: 250px;
        background-color: #333;
        color: #fff;
        text-align: center;
        border-radius: 50px;
        padding: 16px;
        position: fixed;
        z-index: 11000; /* Z-Index alto para flotar sobre todo */
        left: 50%;
        bottom: 30px; 
        transform: translateX(-50%);
        opacity: 0;
        transition: 0.3s;
        box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        font-size: 14px;
        pointer-events: none; /* Evita que bloquee clics si es transparente */
    }

    .toast-notification.show {
        visibility: visible;
        opacity: 1;
        bottom: 50px; /* Animación hacia arriba */
    }

    /* MÓVIL: Ajuste para que no se corte */
    @media (max-width: 768px) {
        .toast-notification {
            left: 20px !important;
            right: 20px !important;
            width: auto !important;
            min-width: 0 !important;
            transform: none !important;
            bottom: 90px !important; /* Más arriba para no chocar con menús */
            border-radius: 12px !important;
            white-space: normal !important;
        }
        .toast-notification.show {
            bottom: 90px !important;
        }
    }
    }
</style>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // 1. Mensajes Flash
    const msgExito = "<?php echo $mensaje_exito ?? ''; ?>";
    const msgError = "<?php echo $mensaje_error ?? ''; ?>";
    if (msgExito) setTimeout(() => mostrarToast(msgExito), 500); 
    if (msgError) setTimeout(() => mostrarToast(msgError, 'error'), 500);

    // 2. Variables Globales
    const checks = document.querySelectorAll('.venta-checkbox');
    const checkMaestro = document.getElementById('select-all');
    const barraMasiva = document.getElementById('barra-masiva');
    const contadorMasivo = document.getElementById('contador-masivo');
    const modal = document.getElementById('modal-confirmar');
    const form = document.getElementById('form-acciones');
    const toast = document.getElementById('toast');
    const tbody = document.getElementById('cuerpo-tabla');

    // --- SOLUCIÓN TEST 3: RECARGA LIMPIA ---
    function recargaLimpia() {
        // Esto elimina el reenvío de formularios POST
        window.location.href = window.location.pathname + window.location.search;
    }

    // --- Lógica Checkbox ---
    const checkMovil = document.getElementById('select-all-mobile');
    
    function recalcular() {
        let n = document.querySelectorAll('.venta-checkbox:checked').length;
        if(contadorMasivo) contadorMasivo.innerText = n + ' sel.';
        if(barraMasiva) {
            if(n > 0) barraMasiva.classList.add('visible'); else barraMasiva.classList.remove('visible');
        }
    }

    document.addEventListener('change', function(e) {
        if(e.target.classList.contains('venta-checkbox')) {
            recalcular();
            if(!e.target.checked && checkMovil) checkMovil.checked = false;
        }
    });

    if(checkMovil) {
        checkMovil.addEventListener('change', function() {
            const estado = this.checked;
            document.querySelectorAll('.venta-checkbox').forEach(c => { 
                if(c.closest('tr').style.display !== 'none') c.checked = estado; 
            });
            recalcular();
        });
    }

    if(checkMaestro) {
        checkMaestro.addEventListener('change', function() {
            const estado = this.checked;
            document.querySelectorAll('.venta-checkbox').forEach(c => { 
                if(c.closest('tr').style.display !== 'none') c.checked = estado; 
            });
            recalcular();
        });
    }

    if(document.getElementById('btn-cancelar-sel')) {
        document.getElementById('btn-cancelar-sel').addEventListener('click', () => {
            document.querySelectorAll('.venta-checkbox').forEach(c => c.checked = false);
            if(checkMaestro) checkMaestro.checked = false;
            if(checkMovil) checkMovil.checked = false;
            recalcular();
        });
    }

    // --- Modales Acción Automática ---
    window.abrirModalIndividual = function(id, accion) {
        document.getElementById('input-id-individual').value = id;
        document.getElementById('input-accion-individual').value = accion;
        document.getElementById('input-ids-masivos').value = ''; 
        let texto = '¿Confirmar acción?';
        if(accion === 'aprobar') texto = '¿Confirmar PAGO del ticket ' + id + '?';
        if(accion === 'recordar') texto = '¿Enviar RECORDATORIO al ticket ' + id + '?';
        if(accion === 'ultima_alerta') texto = '¿Enviar ALERTA FINAL al ticket ' + id + '?';
        if(accion === 'eliminar') texto = '¿ELIMINAR ticket ' + id + ' y liberar números?';
        
        document.getElementById('texto-confirmacion').innerText = texto;
        modal.classList.add('visible');
    }

    window.prepararMasivo = function(accion) {
        let ids = [];
        document.querySelectorAll('.venta-checkbox:checked').forEach(c => ids.push(c.value));
        document.getElementById('input-ids-masivos').value = ids.join(',');
        document.getElementById('input-accion-masiva').value = accion;
        document.getElementById('input-id-individual').value = '';
        document.getElementById('texto-confirmacion').innerText = '¿Aplicar a ' + ids.length + ' tickets seleccionados?';
        modal.classList.add('visible');
    }

    if(document.getElementById('btn-ejecutar-modal')) {
        document.getElementById('btn-ejecutar-modal').addEventListener('click', () => form.submit());
    }
    if(document.getElementById('btn-cancelar-modal')) {
        document.getElementById('btn-cancelar-modal').addEventListener('click', () => modal.classList.remove('visible'));
    }


    // 6. MENÚ MANUAL (5 Argumentos - Corregido)
    window.abrirMenuManual = function(idVenta, nombre, linkCobro, linkAlerta, linkConfirmar) {
        console.log("ID Venta:", idVenta); // Debug
        console.log("Link Confirmar:", linkConfirmar); // Debug

        Swal.fire({
            title: 'Chat Manual',
            text: 'Cliente: ' + nombre,
            html: `
                <div style="display:flex; flex-direction:column; gap:10px; margin-top:15px;">
                    <a href="${linkCobro}" target="_blank" class="btn-principal" style="background:#ffc107; color:#000; text-decoration:none; padding:12px; border-radius:8px; border:none; display:block; text-align:center;">
                        <i class="fa-regular fa-bell"></i> Recordar Pago
                    </a>
                    
                    <a href="${linkAlerta}" target="_blank" class="btn-principal" style="background:#fd7e14; color:#white; text-decoration:none; padding:12px; border-radius:8px; border:none; display:block; text-align:center;">
                        <i class="fa-solid fa-triangle-exclamation"></i> Enviar Ultimátum
                    </a>

                    <button type="button" 
                            onclick="marcarPagadoManual(${idVenta}, '${linkConfirmar}')" 
                            class="btn-principal" 
                            style="background:#28a745; color:white; width:100%; padding:12px; border-radius:8px; border:none; cursor:pointer; font-size:14px; font-weight:600;">
                        <i class="fa-solid fa-check"></i> Confirmar Recibido
                    </button>
                </div>
            `,
            showConfirmButton: false,
            showCloseButton: true
        });
    }

    // LÓGICA DE 2 PASOS (Guardar -> Mostrar Botón WhatsApp)
    window.marcarPagadoManual = function(idVenta, urlWhatsapp) {
        // 1. Mostrar estado de carga
        Swal.fire({
            title: 'Registrando pago...',
            text: 'Por favor espera',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading() }
        });
        
        // 2. Enviar petición al servidor
        fetch('admin_acciones_venta.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ venta_id: idVenta, accion: 'pagar_manual_silencioso' })
        })
        .then(r => r.json())
        .then(d => {
            if(d.success) {
                // Actualizamos la tabla de fondo
                refrescarTabla();

                // 3. MOSTRAR MODAL DE ÉXITO CON EL LINK
                Swal.fire({
                    title: '¡Pago Registrado!',
                    html: `
                        <p style="color:#666; margin-bottom:20px;">La venta pasó a estado <b>PAGADO</b> correctamente.</p>
                        
                        <a href="${urlWhatsapp}" target="_blank" 
                           class="btn-principal" 
                           style="background:#25D366; color:white; text-decoration:none; padding:15px 30px; border-radius:50px; font-size:16px; font-weight:bold; display:inline-flex; align-items:center; gap:10px; box-shadow:0 5px 15px rgba(37,211,102,0.4);">
                            <i class="fa-brands fa-whatsapp" style="font-size:20px;"></i> Enviar Recibo
                        </a>
                        
                        <button onclick="Swal.close()" style="display:block; margin:20px auto 0 auto; background:none; border:none; color:#888; text-decoration:underline; cursor:pointer;">
                            Cerrar y continuar
                        </button>
                    `,
                    icon: 'success',
                    showConfirmButton: false, // Quitamos el botón OK default para usar los nuestros
                    allowOutsideClick: false
                });
            } else {
                Swal.fire('Error', d.message, 'error');
            }
        })
        .catch(err => {
            console.error(err);
            Swal.fire('Error', 'No se pudo conectar con el servidor', 'error');
        });
    }

 // --- LÓGICA DE 2 PASOS (CONFIRMACIÓN + ENVÍO) ---
    window.marcarPagadoManual = function(idVenta, urlWhatsapp) {
        
        // 1. Mostrar cargando (Bloquea la pantalla para evitar doble click)
        Swal.fire({
            title: 'Registrando pago...',
            text: 'Guardando en base de datos',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => { Swal.showLoading() }
        });
        
        // 2. Enviar petición al servidor
        fetch('admin_acciones_venta.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ venta_id: idVenta, accion: 'pagar_manual_silencioso' })
        })
        .then(r => r.json())
        .then(d => {
            if(d.success) {
                // Actualizamos la tabla de fondo
                refrescarTabla();

                // 3. MOSTRAR EL MODAL CON EL BOTÓN (Aquí está la clave)
                // Usamos fire() para sobreescribir el modal de carga
                Swal.fire({
                    icon: 'success',
                    title: '¡Pago Guardado!',
                    html: `
                        <p style="color:#666; font-size:14px; margin-bottom:25px;">
                           La venta ya está "Pagada" en el sistema.<br>
                           Ahora envía el comprobante al cliente:
                        </p>
                        
                        <a href="${urlWhatsapp}" target="_blank" 
                           class="btn-principal" 
                           style="background:#25D366; color:white; text-decoration:none; padding:15px 30px; border-radius:50px; font-size:16px; font-weight:bold; display:inline-flex; align-items:center; gap:10px; box-shadow:0 5px 15px rgba(37,211,102,0.4);">
                            <i class="fa-brands fa-whatsapp" style="font-size:22px;"></i> Enviar Recibo
                        </a>
                        
                        <div style="margin-top:20px;">
                            <button onclick="Swal.close()" style="background:none; border:none; color:#999; text-decoration:underline; cursor:pointer; font-size:13px;">
                                Cerrar y continuar
                            </button>
                        </div>
                    `,
                    showConfirmButton: false, // Quitamos botón azul default
                    allowOutsideClick: false
                });
            } else {
                Swal.fire('Error', d.message, 'error');
            }
        })
        .catch(err => {
            console.error(err);
            Swal.fire('Error', 'No se pudo conectar con el servidor', 'error');
        });
    }

    // --- Buscador ---
    const busc = document.getElementById('buscador-ventas');
    if(busc) {
        busc.addEventListener('input', (e) => {
            const txt = e.target.value.toLowerCase();
            document.querySelectorAll('.fila-venta').forEach(tr => {
                tr.style.display = tr.getAttribute('data-texto').includes(txt) ? '' : 'none';
            });
            recalcular(); 
        });
    }

    // --- Editar Cliente ---
    const modalTel = document.getElementById('modal-editar-tel');
    const inputId = document.getElementById('edit-id-venta');
    const inputTel = document.getElementById('edit-nuevo-numero');
    const inputNom = document.getElementById('edit-nuevo-nombre');

    window.abrirModalTelefono = function(id, numeroActual, nombreActual) {
        inputId.value = id;
        inputTel.value = numeroActual;
        if(inputNom) inputNom.value = nombreActual; 
        modalTel.classList.add('visible');
    }

    window.cerrarModalTel = function() { modalTel.classList.remove('visible'); }

    window.guardarNuevoDatos = function() {
        const id = inputId.value;
        const nuevoTel = inputTel.value.trim();
        const nuevoNom = inputNom ? inputNom.value.trim() : '';

        if(!nuevoTel || !nuevoNom) { mostrarToast("Datos incompletos", "error"); return; }

        const btnGuardar = document.querySelector('#modal-editar-tel .btn-principal');
        const textoOriginal = btnGuardar.innerText;
        btnGuardar.innerText = "Guardando...";
        btnGuardar.disabled = true;

        fetch('admin_ajax_editar_telefono.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, telefono: nuevoTel, nombre: nuevoNom })
        })
        .then(r => r.json())
        .then(data => {
            if(data.success) {
                mostrarToast("¡Datos actualizados!");
                cerrarModalTel();
                // AQUÍ LA CORRECCIÓN CLAVE: Usamos recargaLimpia() en vez de reload()
                setTimeout(() => recargaLimpia(), 1000); 
            } else {
                mostrarToast("Error: " + data.message, "error");
                btnGuardar.innerText = textoOriginal;
                btnGuardar.disabled = false;
            }
        })
        .catch(e => {
            mostrarToast("Error de conexión", "error");
            btnGuardar.innerText = textoOriginal;
            btnGuardar.disabled = false;
        });
    }

    function mostrarToast(mensaje, tipo = 'normal') {
        if(!toast) return;
        toast.innerText = mensaje;
        toast.style.background = (tipo === 'error') ? '#dc3545' : '#333';
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 3000);
    }

    // --- LIVE RELOAD ---
    const estadoActual = "<?php echo $filtro_estado; ?>";
    const sorteoActual = "<?php echo $filtro_sorteo; ?>";
    const counterPend = document.getElementById('contador-pendientes');
    const counterPag = document.getElementById('contador-pagados');

    function refrescarTabla() {
        const haySeleccion = document.querySelectorAll('.venta-checkbox:checked').length > 0;
        const modalAbierto = document.querySelector('.modal-overlay.visible');
        const swalAbierto = document.querySelector('.swal2-container');
        
        if(haySeleccion || modalAbierto || swalAbierto) return;

        fetch(`admin_ajax_refrescar_ventas.php?estado=${estadoActual}&sorteo=${sorteoActual}`)
        .then(r => r.json())
        .then(data => {
            if(tbody && tbody.innerHTML.length !== data.html.length) {
                tbody.innerHTML = data.html;
                if(busc && busc.value) {
                    const txt = busc.value.toLowerCase();
                    document.querySelectorAll('.fila-venta').forEach(tr => {
                        tr.style.display = tr.getAttribute('data-texto').includes(txt) ? '' : 'none';
                    });
                }
            }
            if(counterPend) counterPend.innerText = data.pendientes;
            if(counterPag) counterPag.innerText = data.pagados;
        })
        .catch(e => {}); 
    }
    // 6. MENÚ MANUAL (5 Argumentos)
    window.abrirMenuManual = function(idVenta, nombre, linkCobro, linkAlerta, linkConfirmar) {
        // Debug: Verificamos en consola si llega el link
        console.log("Link Confirmar:", linkConfirmar); 

        Swal.fire({
            title: 'Chat Manual',
            text: 'Cliente: ' + nombre,
            html: `
                <div style="display:flex; flex-direction:column; gap:10px; margin-top:15px;">
                    <a href="${linkCobro}" target="_blank" class="btn-principal" style="background:#ffc107; color:#000; text-decoration:none; padding:12px; border-radius:8px; border:none; display:block; text-align:center;">
                        <i class="fa-regular fa-bell"></i> Recordar Pago
                    </a>
                    
                    <a href="${linkAlerta}" target="_blank" class="btn-principal" style="background:#fd7e14; color:#white; text-decoration:none; padding:12px; border-radius:8px; border:none; display:block; text-align:center;">
                        <i class="fa-solid fa-triangle-exclamation"></i> Enviar Ultimátum
                    </a>

                    <button type="button" 
                            onclick="marcarPagadoManual(${idVenta}, '${linkConfirmar}')" 
                            class="btn-principal" 
                            style="background:#28a745; color:white; width:100%; padding:12px; border-radius:8px; border:none; cursor:pointer; font-size:14px; font-weight:600;">
                        <i class="fa-solid fa-check"></i> Confirmar Recibido
                    </button>
                </div>
            `,
            showConfirmButton: false,
            showCloseButton: true
        });
    }

    setInterval(refrescarTabla, 3000);
});
</script>
<?php require '../includes/admin_footer.php'; ?>