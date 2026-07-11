CREATE TABLE IF NOT EXISTS negocios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(60) NOT NULL UNIQUE,
  nombre VARCHAR(160) NOT NULL,
  descripcion TEXT NULL,
  ubicacion VARCHAR(255) NULL,
  telefono VARCHAR(40) NULL,
  politicas TEXT NULL,
  instrucciones_extra TEXT NULL,
  intervalo_minutos INT NOT NULL DEFAULT 30,
  numero_whatsapp VARCHAR(40) NULL UNIQUE,
  numero_avisos VARCHAR(40) NULL,
  limite_mensajes_mes INT NOT NULL DEFAULT 0,
  recordatorio_horas_antes INT NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(160) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  nombre VARCHAR(160) NOT NULL,
  rol VARCHAR(20) NOT NULL DEFAULT 'admin',
  id_negocio INT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_negocio) REFERENCES negocios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS horarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_negocio INT NOT NULL,
  dia VARCHAR(10) NOT NULL,
  abre VARCHAR(5) NULL,
  cierra VARCHAR(5) NULL,
  UNIQUE KEY uniq_negocio_dia (id_negocio, dia),
  FOREIGN KEY (id_negocio) REFERENCES negocios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servicios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_negocio INT NOT NULL,
  nombre VARCHAR(160) NOT NULL,
  precio DECIMAL(10,2) NOT NULL DEFAULT 0,
  duracion INT NOT NULL DEFAULT 30,
  orden INT NOT NULL DEFAULT 0,
  FOREIGN KEY (id_negocio) REFERENCES negocios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Personal que atiende (barberos, estilistas, especialistas...). Si un negocio
-- tiene varias filas aqui, el cliente puede pedir cita con una persona en
-- especifico y cada quien lleva su propia agenda. Sin filas = un solo lugar.
CREATE TABLE IF NOT EXISTS recursos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_negocio INT NOT NULL,
  nombre VARCHAR(120) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  orden INT NOT NULL DEFAULT 0,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_negocio (id_negocio),
  FOREIGN KEY (id_negocio) REFERENCES negocios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS citas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_negocio INT NOT NULL,
  nombre VARCHAR(160) NOT NULL,
  servicio VARCHAR(200) NULL,
  profesional VARCHAR(120) NULL,
  fecha DATE NULL,
  dia_texto VARCHAR(120) NULL,
  hora VARCHAR(8) NULL,
  duracion INT NOT NULL DEFAULT 30,
  contacto VARCHAR(80) NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
  recordado_en DATETIME NULL,
  pagado TINYINT(1) NOT NULL DEFAULT 0,
  metodo_pago VARCHAR(20) NULL,
  monto_cobrado DECIMAL(10,2) NULL,
  pagado_en DATETIME NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_negocio_fecha (id_negocio, fecha),
  FOREIGN KEY (id_negocio) REFERENCES negocios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expira_en DATETIME NOT NULL,
  usado TINYINT(1) NOT NULL DEFAULT 0,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_token (token_hash),
  FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eventos_seguridad (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo VARCHAR(40) NOT NULL,
  detalle TEXT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(500) NULL,
  id_usuario INT NULL,
  id_negocio INT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tipo_fecha (tipo, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mensajes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_negocio INT NOT NULL,
  contacto VARCHAR(80) NOT NULL,
  rol VARCHAR(12) NOT NULL,
  contenido TEXT NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_negocio_contacto (id_negocio, contacto),
  FOREIGN KEY (id_negocio) REFERENCES negocios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uso_mensual (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_negocio INT NOT NULL,
  periodo CHAR(7) NOT NULL,
  mensajes INT NOT NULL DEFAULT 0,
  tokens_entrada BIGINT NOT NULL DEFAULT 0,
  tokens_salida BIGINT NOT NULL DEFAULT 0,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_negocio_periodo (id_negocio, periodo),
  FOREIGN KEY (id_negocio) REFERENCES negocios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enlace muchos-a-muchos: un usuario puede administrar varios negocios.
CREATE TABLE IF NOT EXISTS usuario_negocio (
  id_usuario INT NOT NULL,
  id_negocio INT NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_usuario, id_negocio),
  FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
  FOREIGN KEY (id_negocio) REFERENCES negocios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Escalación a humano: si existe fila para (negocio, contacto), el bot queda en
-- pausa para ese chat (lo atiende una persona) hasta que se reactive en el panel.
CREATE TABLE IF NOT EXISTS atencion_humana (
  id_negocio INT NOT NULL,
  contacto VARCHAR(80) NOT NULL,
  motivo VARCHAR(255) NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_negocio, contacto),
  FOREIGN KEY (id_negocio) REFERENCES negocios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
