import os
import sys
import re
import argparse
import pandas as pd
import mysql.connector
from mysql.connector import Error

def parse_conexion_php(php_file):
    """
    Attempts to read database credentials from conexion.php in the project root.
    """
    if not os.path.exists(php_file):
        return {}
    try:
        with open(php_file, 'r', encoding='utf-8') as f:
            content = f.read()
        
        servername = re.search(r'\$servername\s*=\s*["\']([^"\']+)["\']', content)
        username = re.search(r'\$username\s*=\s*["\']([^"\']+)["\']', content)
        password = re.search(r'\$password\s*=\s*["\']([^"\']+)["\']', content)
        dbname = re.search(r'\$dbname\s*=\s*["\']([^"\']+)["\']', content)
        
        return {
            'host': servername.group(1) if servername else None,
            'user': username.group(1) if username else None,
            'password': password.group(1) if password else None,
            'database': dbname.group(1) if dbname else None
        }
    except Exception as e:
        print(f"Advertencia: No se pudo leer {php_file}: {e}")
        return {}

def guess_nivel_educativo(tipo_trabajador, nombre_ie, db_nivel=None):
    """
    Guesses the level (nivel_educativo) based on worker type and IE name.
    If database level is provided, it attempts to map/clean it.
    """
    # Allowed: 'Inicial', 'Primaria', 'Secundaria', 'CEBA', 'CETPRO', 'EBE', 'Administrativo'
    if tipo_trabajador == 'ADMINISTRATIVO':
        return 'Administrativo'
        
    if db_nivel:
        db_nivel_clean = db_nivel.strip().title()
        if db_nivel_clean in ['Inicial', 'Primaria', 'Secundaria', 'CEBA', 'CETPRO', 'EBE', 'Administrativo']:
            return db_nivel_clean

    nombre = nombre_ie.upper()
    if 'INICIAL' in nombre or 'I.E.I.' in nombre or 'I.E.I' in nombre or 'IEI' in nombre:
        return 'Inicial'
    elif 'CEBA' in nombre:
        return 'CEBA'
    elif 'CETPRO' in nombre:
        return 'CETPRO'
    elif 'EBE' in nombre or 'PRITE' in nombre or 'ESPECIAL' in nombre:
        return 'EBE'
    elif 'SECUNDARIA' in nombre or 'C.N.' in nombre or 'C.M.' in nombre:
        return 'Secundaria'
    elif 'PRIMARIA' in nombre or any(str(i) in nombre for i in range(10)):
        # Common pattern: IE names starting or containing numbers (e.g. 22240) are primary/secondary,
        # but in Peru typical numbers (like 22xxx) are primary schools.
        return 'Primaria'
        
    return 'Secundaria'

def connect_db(args, php_creds):
    """
    Tries to connect to the database using command arguments, failing back to conexion.php credentials,
    and then local defaults.
    """
    host = args.host or php_creds.get('host')
    user = args.user or php_creds.get('user')
    password = args.password or php_creds.get('password')
    database = args.database or php_creds.get('database')
    
    # Try specified credentials
    if host:
        try:
            print(f"Intentando conectar a {host} ({database})...")
            conn = mysql.connector.connect(
                host=host,
                user=user,
                password=password,
                database=database,
                connect_timeout=3
            )
            if conn.is_connected():
                return conn
        except Error as e:
            print(f"No se pudo conectar a {host}: {e}")

    # Fallback: Try localhost with common options
    local_attempts = [
        {'host': 'localhost', 'user': 'root', 'password': '', 'database': '4582023_ugel'},
        {'host': 'localhost', 'user': 'root', 'password': 'root', 'database': '4582023_ugel'},
        {'host': 'localhost', 'user': 'root', 'password': 'admin', 'database': '4582023_ugel'},
    ]
    
    print("\nIntentando conectar a MySQL local (localhost)...")
    for attempt in local_attempts:
        try:
            conn = mysql.connector.connect(
                host=attempt['host'],
                user=attempt['user'],
                password=attempt['password'],
                database=attempt['database'],
                connect_timeout=2
            )
            if conn.is_connected():
                print(f"¡Conexión exitosa a base de datos local! (Host: {attempt['host']}, Usuario: {attempt['user']})")
                return conn
        except Error:
            continue
            
    print("\n[!] No se pudo establecer conexión con ninguna base de datos.")
    print("El análisis continuará de manera local ('Dry Run' / Solo Lectura) sobre el archivo de Excel.")
    return None

def main():
    parser = argparse.ArgumentParser(description="Analiza y sube datos de docentes desde planilla Nexus a MySQL.")
    parser.add_argument('--host', help="Servidor MySQL (ej. localhost o IP)")
    parser.add_argument('--user', help="Usuario MySQL")
    parser.add_argument('--password', help="Contraseña MySQL")
    parser.add_argument('--database', help="Nombre de la Base de Datos")
    parser.add_argument('--upload', action='store_true', help="Ejecutar la inserción/actualización real en la base de datos.")
    
    args = parser.parse_args()
    
    excel_file = 'nexus al 28-04-2026 (3).xls'
    php_file = '../../conexion.php'
    
    if not os.path.exists(excel_file):
        print(f"Error: No se encontró el archivo de datos '{excel_file}' en el directorio actual.")
        sys.exit(1)
        
    print("=" * 70)
    print(" ANALIZADOR DE DATOS NEXUS ")
    print("=" * 70)
    
    # 1. Leer planilla Excel
    try:
        print(f"Leyendo '{excel_file}'...")
        df = pd.read_excel(excel_file)
        print(f"Total de registros encontrados en Excel: {len(df)}")
    except Exception as e:
        print(f"Error al leer el archivo Excel: {e}")
        sys.exit(1)
        
    # 2. Conectar a la Base de Datos
    php_creds = parse_conexion_php(php_file)
    conn = connect_db(args, php_creds)
    
    db_ie_codes = {}
    db_personal_dnis = set()
    
    if conn:
        try:
            cursor = conn.cursor()
            # Obtener códigos de locales e IDs de IE en base de datos
            cursor.execute("SELECT id, codigo_local, nombre_ie, nivel_modalidad FROM instituciones")
            for row in cursor.fetchall():
                # row = (id, codigo_local, nombre_ie, nivel_modalidad)
                db_ie_codes[str(row[1]).strip()] = {
                    'id': row[0],
                    'nombre_ie': row[2],
                    'nivel_modalidad': row[3]
                }
            
            # Obtener DNIs de personal existente
            cursor.execute("SELECT dni FROM asistencia_personal")
            db_personal_dnis = {str(row[0]).strip() for row in cursor.fetchall()}
            
            print(f"Conexión activa: Cargados {len(db_ie_codes)} colegios y {len(db_personal_dnis)} trabajadores de la base de datos.")
        except Error as e:
            print(f"Error cargando datos de referencia desde la BD: {e}")
            conn = None
            
    # 3. Analizar datos del Excel
    excel_ie_codes = df['CODIGO LOCAL'].dropna().unique()
    excel_ie_codes = [str(int(code) if isinstance(code, float) else code).strip() for code in excel_ie_codes]
    
    print("\n--- ANÁLISIS DE COLEGIOS (INSTITUCIONES EDUCATIVAS) ---")
    print(f"Total de códigos locales distintos en Excel: {len(excel_ie_codes)}")
    
    if conn:
        matching_codes = [code for code in excel_ie_codes if code in db_ie_codes]
        missing_in_db = [code for code in excel_ie_codes if code not in db_ie_codes]
        missing_in_excel = [code for code in db_ie_codes if code not in excel_ie_codes]
        
        print(f"  -> Coinciden con la BD: {len(matching_codes)}")
        print(f"  -> Códigos locales en Excel que NO existen en la BD: {len(missing_in_db)}")
        if missing_in_db:
            print(f"     Ejemplos no encontrados: {missing_in_db[:10]}")
        print(f"  -> Códigos locales en la BD que NO están en este Excel: {len(missing_in_excel)}")
    else:
        print("  -> (No conectado a la BD, no se puede mapear correspondencia exacta con base de datos)")
        
    # 4. Verificar calidad y mapeo de datos de personal
    print("\n--- ANÁLISIS DE PERSONAL Y MAQUEO DE CAMPOS ---")
    
    vacantes_count = 0
    valid_workers_count = 0
    skipped_rows = 0
    
    preview_data = []
    
    for idx, row in df.iterrows():
        dni = str(row['DOCUMENTO DE IDENTIDAD']).strip()
        
        if pd.isna(row['DOCUMENTO DE IDENTIDAD']) or not dni or dni.upper() == 'VACANTE':
            vacantes_count += 1
            continue
            
        valid_workers_count += 1
        
        # Mapear colegio
        local_code = str(row['CODIGO LOCAL']).strip()
        local_code = str(int(float(local_code))) if local_code.replace('.','',1).isdigit() else local_code
        
        ie_exists = local_code in db_ie_codes if conn else True
        
        # Concatenar nombres
        ap_pat = str(row['APELLIDO PATERNO']).strip() if not pd.isna(row['APELLIDO PATERNO']) else ''
        ap_mat = str(row['APELLIDO MATERNO']).strip() if not pd.isna(row['APELLIDO MATERNO']) else ''
        nombres = str(row['NOMBRES']).strip() if not pd.isna(row['NOMBRES']) else ''
        
        if ap_mat:
            apellidos_nombres = f"{ap_pat} {ap_mat}, {nombres}".strip().upper()
        else:
            apellidos_nombres = f"{ap_pat}, {nombres}".strip().upper()
            
        cargo = str(row['CARGO']).strip() if not pd.isna(row['CARGO']) else 'Docente'
        
        # Condicion laboral
        sit = str(row['SITUACION LABORAL']).strip().upper()
        if 'NOMBRADO' in sit:
            condicion = 'Nombrado'
        elif 'CONTRATADO' in sit:
            condicion = 'Contratado'
        elif 'DESIGNADO' in sit:
            condicion = 'Designado'
        elif 'ENCARGADO' in sit:
            condicion = 'Encargado'
        else:
            condicion = sit.title()
            
        # Jornada laboral
        jornada = str(row['JORNADA LABORAL']).strip()
        
        # Nivel educativo
        tipo_trabajador = str(row['TIPO DE TRABAJADOR']).strip().upper()
        db_nivel = db_ie_codes[local_code]['nivel_modalidad'] if (conn and ie_exists) else None
        nivel = guess_nivel_educativo(tipo_trabajador, str(row['NOMBRE DE LA INSTITUCION EDUCATIVA']), db_nivel)
        
        if len(preview_data) < 5:
            preview_data.append({
                'DNI': dni,
                'Apellidos y nombres': apellidos_nombres,
                'Cargo': cargo,
                'Condicion': condicion,
                'Jornada': jornada,
                'Nivel educativo': nivel,
                'Código Local': local_code,
                'IE Mapeada': ie_exists
            })
            
    print(f"Total registros analizados: {len(df)}")
    print(f"  -> Vacantes / Registros vacíos omitidos: {vacantes_count}")
    print(f"  -> Trabajadores válidos para importar: {valid_workers_count}")
    
    # Mostrar vista previa de los datos mapeados
    print("\n--- VISTA PREVIA DE MAPEADOS A BASE DE DATOS (Primeros 5 registros) ---")
    df_preview = pd.DataFrame(preview_data)
    print(df_preview.to_string(index=False))
    
    # Exportar a JSON para evitar dependencias de PhpSpreadsheet en el servidor
    import json
    colegios_dict = {}
    personal_list = []
    
    for idx, row in df.iterrows():
        dni = str(row['DOCUMENTO DE IDENTIDAD']).strip()
        if pd.isna(row['DOCUMENTO DE IDENTIDAD']) or not dni or dni.upper() == 'VACANTE':
            continue
            
        local_code = str(row['CODIGO LOCAL']).strip()
        local_code = str(int(float(local_code))) if local_code.replace('.','',1).isdigit() else local_code
        
        ie_name = str(row['NOMBRE DE LA INSTITUCION EDUCATIVA']).strip()
        colegios_dict[local_code] = ie_name
        
        ap_pat = str(row['APELLIDO PATERNO']).strip() if not pd.isna(row['APELLIDO PATERNO']) else ''
        ap_mat = str(row['APELLIDO MATERNO']).strip() if not pd.isna(row['APELLIDO MATERNO']) else ''
        nombres = str(row['NOMBRES']).strip() if not pd.isna(row['NOMBRES']) else ''
        
        if ap_mat:
            apellidos_nombres = f"{ap_pat} {ap_mat}, {nombres}".strip().upper()
        else:
            apellidos_nombres = f"{ap_pat}, {nombres}".strip().upper()
            
        cargo = str(row['CARGO']).strip() if not pd.isna(row['CARGO']) else 'Docente'
        
        sit = str(row['SITUACION LABORAL']).strip().upper()
        if 'NOMBRADO' in sit:
            condicion = 'Nombrado'
        elif 'CONTRATADO' in sit:
            condicion = 'Contratado'
        elif 'DESIGNADO' in sit:
            condicion = 'Designado'
        elif 'ENCARGADO' in sit:
            condicion = 'Encargado'
        else:
            condicion = sit.title()
            
        jornada = str(row['JORNADA LABORAL']).strip()
        tipo_trabajador = str(row['TIPO DE TRABAJADOR']).strip().upper()
        
        personal_list.append({
            'dni': dni,
            'apellidos_nombres': apellidos_nombres,
            'cargo': cargo,
            'condicion_laboral': condicion,
            'jornada_laboral': jornada,
            'tipo_trabajador': tipo_trabajador,
            'nombre_ie_excel': ie_name,
            'codigo_local': local_code
        })
        
    output_data = {
        'colegios': colegios_dict,
        'personal': personal_list
    }
    
    json_path = 'nexus_data.json'
    try:
        with open(json_path, 'w', encoding='utf-8') as f:
            json.dump(output_data, f, ensure_ascii=False, indent=2)
    except Exception as json_err:
        print(f"Error al escribir JSON: {json_err}")
        return
        
    print(f"\n[OK] Exportacion exitosa: Se creo '{json_path}' con {len(colegios_dict)} colegios y {len(personal_list)} trabajadores.")
    
    # 5. Ejecutar la subida si se solicita
    if args.upload:
        if not conn:
            print("\n[ERROR] No se puede realizar la subida (--upload) porque no hay conexión a la base de datos.")
            print("Por favor, ejecute especificando las credenciales correctas. Ejemplo:")
            print("  py main.py --host localhost --user root --password tu_clave --database 4582023_ugel --upload")
            sys.exit(1)
            
        print("\n" + "=" * 50)
        print(" INICIANDO CARGA EN BASE DE DATOS ")
        print("=" * 50)
        
        inserted = 0
        updated = 0
        errors = 0
        
        try:
            cursor = conn.cursor()
            for idx, row in df.iterrows():
                dni = str(row['DOCUMENTO DE IDENTIDAD']).strip()
                if pd.isna(row['DOCUMENTO DE IDENTIDAD']) or not dni or dni.upper() == 'VACANTE':
                    continue
                    
                local_code = str(row['CODIGO LOCAL']).strip()
                local_code = str(int(float(local_code))) if local_code.replace('.','',1).isdigit() else local_code
                
                if local_code not in db_ie_codes:
                    # Skip if the school doesn't exist in our institutions database
                    continue
                    
                id_ie = db_ie_codes[local_code]['id']
                db_nivel = db_ie_codes[local_code]['nivel_modalidad']
                
                # Format Name
                ap_pat = str(row['APELLIDO PATERNO']).strip() if not pd.isna(row['APELLIDO PATERNO']) else ''
                ap_mat = str(row['APELLIDO MATERNO']).strip() if not pd.isna(row['APELLIDO MATERNO']) else ''
                nombres = str(row['NOMBRES']).strip() if not pd.isna(row['NOMBRES']) else ''
                
                if ap_mat:
                    apellidos_nombres = f"{ap_pat} {ap_mat}, {nombres}".strip().upper()
                else:
                    apellidos_nombres = f"{ap_pat}, {nombres}".strip().upper()
                    
                cargo = str(row['CARGO']).strip() if not pd.isna(row['CARGO']) else 'Docente'
                
                # Format Condicion
                sit = str(row['SITUACION LABORAL']).strip().upper()
                if 'NOMBRADO' in sit:
                    condicion = 'Nombrado'
                elif 'CONTRATADO' in sit:
                    condicion = 'Contratado'
                elif 'DESIGNADO' in sit:
                    condicion = 'Designado'
                elif 'ENCARGADO' in sit:
                    condicion = 'Encargado'
                else:
                    condicion = sit.title()
                    
                jornada = str(row['JORNADA LABORAL']).strip()
                tipo_trabajador = str(row['TIPO DE TRABAJADOR']).strip().upper()
                nivel = guess_nivel_educativo(tipo_trabajador, str(row['NOMBRE DE LA INSTITUCION EDUCATIVA']), db_nivel)
                
                try:
                    # Check if (id_ie, dni) exists in database
                    cursor.execute("SELECT id FROM asistencia_personal WHERE id_ie = %s AND dni = %s", (id_ie, dni))
                    row_exists = cursor.fetchone()
                    
                    if row_exists:
                        # Update existing
                        cursor.execute("""
                            UPDATE asistencia_personal
                            SET apellidos_nombres = %s, cargo = %s, condicion_laboral = %s,
                                jornada_laboral = %s, nivel_educativo = %s, estado = 'activo'
                            WHERE id = %s
                        """, (apellidos_nombres, cargo, condicion, jornada, nivel, row_exists[0]))
                        updated += 1
                    else:
                        # Insert new
                        cursor.execute("""
                            INSERT INTO asistencia_personal (id_ie, dni, apellidos_nombres, cargo, condicion_laboral, jornada_laboral, nivel_educativo, estado)
                            VALUES (%s, %s, %s, %s, %s, %s, %s, 'activo')
                        """, (id_ie, dni, apellidos_nombres, cargo, condicion, jornada, nivel))
                        inserted += 1
                except Error as db_err:
                    print(f"Error procesando registro DNI {dni}: {db_err}")
                    errors += 1
                    
            conn.commit()
            print("\nPROCESAMIENTO TERMINADO CON ÉXITO:")
            print(f"  -> Trabajadores insertados: {inserted}")
            print(f"  -> Trabajadores actualizados/reactivados: {updated}")
            print(f"  -> Errores: {errors}")
            
        except Exception as upload_err:
            conn.rollback()
            print(f"Error crítico durante la transacción de subida: {upload_err}")
        finally:
            cursor.close()
            conn.close()
    else:
        print("\n[INFO] Ejecutado en modo solo lectura. Use la bandera '--upload' para subir los datos.")
        print("Ejemplo para guardar en BD local:")
        print("  py main.py --upload")

if __name__ == '__main__':
    main()
