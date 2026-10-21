-- =====================================================================
-- EPO 381 - Sistema de Control Escolar
-- Esquema de Base de Datos PostgreSQL
-- =====================================================================
-- =====================================================================
-- USUARIOS  (cuenta de acceso: alumno, docente, orientador, admin)
-- =====================================================================
CREATE TABLE usuarios (
    id                  SERIAL PRIMARY KEY,
    usuario             VARCHAR(30)  UNIQUE NOT NULL,   -- clave de acceso / matrícula
    password_hash       TEXT         NOT NULL,
    rol                 VARCHAR(20)  NOT NULL CHECK (rol IN ('alumno','docente','orientador','admin')),
    nombre              VARCHAR(100) NOT NULL,
    apellido_paterno    VARCHAR(100) NOT NULL,
    apellido_materno    VARCHAR(100) DEFAULT '',
    activo              BOOLEAN      NOT NULL DEFAULT TRUE,
    primer_ingreso      BOOLEAN      NOT NULL DEFAULT TRUE,  -- fuerza completar perfil (solo aplica a alumno)
    creado_por          INT          REFERENCES usuarios(id) ON DELETE SET NULL,
    created_at          TIMESTAMP    NOT NULL DEFAULT now()
);
CREATE INDEX idx_usuarios_rol ON usuarios(rol);

-- =====================================================================
-- GRUPOS
-- =====================================================================
CREATE TABLE grupos (
    id              SERIAL PRIMARY KEY,
    nombre          VARCHAR(20)  UNIQUE NOT NULL,   -- Ej. "1°A"
    grado           SMALLINT     NOT NULL CHECK (grado BETWEEN 1 AND 3),
    semestre        SMALLINT     NOT NULL DEFAULT 1 CHECK (semestre IN (1,2)), -- semestre dentro del grado
    turno           VARCHAR(15)  NOT NULL DEFAULT 'Matutino' CHECK (turno = 'Matutino'), -- solo se maneja turno matutino
    ciclo_escolar   VARCHAR(15)  NOT NULL DEFAULT '2026-2027',
    activo          BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP    NOT NULL DEFAULT now()
);

-- =====================================================================
-- MATERIAS
-- =====================================================================
CREATE TABLE materias (
    id       SERIAL PRIMARY KEY,
    clave    VARCHAR(20),
    nombre   VARCHAR(100) UNIQUE NOT NULL,

    semestre SMALLINT CHECK (semestre BETWEEN 1 AND 6),
    activo   BOOLEAN NOT NULL DEFAULT TRUE
);
CREATE INDEX idx_materias_semestre ON materias(semestre);

-- =====================================================================
-- ALUMNOS  (extiende a usuarios con rol='alumno')
-- =====================================================================
CREATE TABLE alumnos (
    usuario_id              INT PRIMARY KEY REFERENCES usuarios(id) ON DELETE CASCADE,
    matricula               VARCHAR(30) UNIQUE NOT NULL,
    grupo_id                INT REFERENCES grupos(id),

    curp                    TEXT,
    fecha_nacimiento        DATE,
    sexo                    VARCHAR(15),
    telefono                TEXT,
    email_personal          TEXT,
    tutor_nombre            VARCHAR(150),
    tutor_telefono          TEXT,

    -- Domicilio
    calle                   TEXT,
    numero                  TEXT,
    colonia                 TEXT,
    municipio               VARCHAR(100),
    estado                  VARCHAR(100),
    cp                      TEXT,

    -- Procedencia
    escuela_procedencia         VARCHAR(150),
    municipio_procedencia       VARCHAR(100),
    promedio_secundaria         NUMERIC(4,1),

    -- Estatus académico
    estatus         VARCHAR(15) NOT NULL DEFAULT 'activo' CHECK (estatus IN ('activo','baja')),
    fecha_baja      DATE,
    motivo_baja     TEXT,

    perfil_completo BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at      TIMESTAMP
);
CREATE INDEX idx_alumnos_grupo ON alumnos(grupo_id);

-- =====================================================================
-- ASIGNACIONES DOCENTE (materia que imparte en un grupo)
-- =====================================================================
CREATE TABLE docente_asignaciones (
    id          SERIAL PRIMARY KEY,
    docente_id  INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    materia_id  INT NOT NULL REFERENCES materias(id) ON DELETE CASCADE,
    grupo_id    INT NOT NULL REFERENCES grupos(id) ON DELETE CASCADE,
    UNIQUE(docente_id, materia_id, grupo_id)
);
CREATE INDEX idx_docasig_docente ON docente_asignaciones(docente_id);
CREATE INDEX idx_docasig_grupo   ON docente_asignaciones(grupo_id);

-- =====================================================================
-- ASIGNACIONES ORIENTADOR (grupos a su cargo)
-- =====================================================================
CREATE TABLE orientador_grupos (
    id              SERIAL PRIMARY KEY,
    orientador_id   INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    grupo_id        INT NOT NULL REFERENCES grupos(id) ON DELETE CASCADE,
    UNIQUE(orientador_id, grupo_id)
);
CREATE INDEX idx_oriasig_orientador ON orientador_grupos(orientador_id);

-- =====================================================================
-- PERIODOS DE EVALUACIÓN (catálogo fijo: 3 parciales)
-- =====================================================================
CREATE TABLE periodos (
    id      SERIAL PRIMARY KEY,
    nombre  VARCHAR(20) NOT NULL,
    orden   SMALLINT NOT NULL UNIQUE
);
INSERT INTO periodos (nombre, orden) VALUES
    ('Parcial 1', 1),
    ('Parcial 2', 2),
    ('Parcial 3', 3);

-- =====================================================================
-- CALIFICACIONES  (capturadas únicamente por el Orientador)
-- =====================================================================
CREATE TABLE calificaciones (
    id              SERIAL PRIMARY KEY,
    alumno_id       INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    materia_id      INT NOT NULL REFERENCES materias(id) ON DELETE CASCADE,
    grupo_id        INT NOT NULL REFERENCES grupos(id) ON DELETE CASCADE,
    periodo_id      INT NOT NULL REFERENCES periodos(id),
    calificacion    NUMERIC(4,1) NOT NULL CHECK (calificacion BETWEEN 0 AND 10),
    faltas          SMALLINT NOT NULL DEFAULT 0,

    ciclo_escolar     VARCHAR(15) NOT NULL,
    grado_captura     SMALLINT,
    semestre_captura  SMALLINT,
    orientador_id   INT REFERENCES usuarios(id) ON DELETE SET NULL,
    fecha_registro  TIMESTAMP NOT NULL DEFAULT now(),
    UNIQUE(alumno_id, materia_id, periodo_id, ciclo_escolar)
);
CREATE INDEX idx_calif_alumno  ON calificaciones(alumno_id);
CREATE INDEX idx_calif_grupo   ON calificaciones(grupo_id);
CREATE INDEX idx_calif_materia ON calificaciones(materia_id);

-- =====================================================================
-- EXTRAORDINARIOS / REGULARIZACIÓN (1ª, 2ª y 3ª oportunidad)
-- =====================================================================
CREATE TABLE extraordinarios (
    id                  SERIAL PRIMARY KEY,
    alumno_id           INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    materia_id          INT NOT NULL REFERENCES materias(id) ON DELETE CASCADE,
    grupo_id            INT NOT NULL REFERENCES grupos(id) ON DELETE CASCADE,
    ciclo_escolar       VARCHAR(15) NOT NULL,
    oportunidad         SMALLINT NOT NULL CHECK (oportunidad IN (1,2,3)),
    calificacion        NUMERIC(4,1) CHECK (calificacion IS NULL OR calificacion BETWEEN 0 AND 10),
    fecha_regularizacion DATE,
    orientador_id       INT REFERENCES usuarios(id) ON DELETE SET NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT now(),
    UNIQUE (alumno_id, materia_id, ciclo_escolar, oportunidad)
);
CREATE INDEX idx_extra_alumno ON extraordinarios(alumno_id);
CREATE INDEX idx_extra_grupo  ON extraordinarios(grupo_id);

-- =====================================================================
-- HISTORIAL DE CAMBIOS DE GRUPO (bitácora)
-- =====================================================================
CREATE TABLE historial_grupos (
    id                  SERIAL PRIMARY KEY,
    alumno_id           INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    grupo_anterior_id   INT REFERENCES grupos(id),
    grupo_nuevo_id      INT REFERENCES grupos(id),
    motivo              TEXT,
    admin_id            INT REFERENCES usuarios(id) ON DELETE SET NULL,
    fecha               TIMESTAMP NOT NULL DEFAULT now()
);

-- =====================================================================
-- REPORTES DE CONDUCTA (docente y orientador)
-- =====================================================================
CREATE TABLE reportes_conducta (
    id              SERIAL PRIMARY KEY,
    alumno_id       INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    autor_id        INT REFERENCES usuarios(id) ON DELETE SET NULL,
    autor_nombre_hist VARCHAR(150), -- respaldo del nombre del autor por si su cuenta es eliminada después
    autor_rol       VARCHAR(20) NOT NULL,
    materia_id      INT REFERENCES materias(id),
    fecha           DATE NOT NULL DEFAULT CURRENT_DATE,
    motivo          VARCHAR(150) NOT NULL,
    descripcion     TEXT NOT NULL,
    medida          VARCHAR(150),
    created_at      TIMESTAMP NOT NULL DEFAULT now()
);
CREATE INDEX idx_reportes_alumno ON reportes_conducta(alumno_id);

-- =====================================================================
-- AVISOS INSTITUCIONALES (se muestran en la página pública de inicio)
-- =====================================================================
DROP TABLE IF EXISTS avisos CASCADE;
CREATE TABLE avisos (
    id              SERIAL PRIMARY KEY,
    titulo          VARCHAR(150) NOT NULL,
    contenido       TEXT NOT NULL,
    imagen          VARCHAR(255),           -- ruta relativa dentro de /uploads/avisos/
    activo          BOOLEAN NOT NULL DEFAULT TRUE,
    orden           INT NOT NULL DEFAULT 0,
    autor_id        INT REFERENCES usuarios(id) ON DELETE SET NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT now(),
    updated_at      TIMESTAMP
);

-- =====================================================================
-- FIN DEL ESQUEMA
-- =====================================================================
