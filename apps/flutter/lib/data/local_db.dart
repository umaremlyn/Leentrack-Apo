import 'dart:convert';

import 'package:sqlite3/sqlite3.dart';

/// The device's own copy of what it needs to keep selling without a connection, plus the outbox of things the cashier did.
/// One database file PER BUSINESS (and per user), so switching business can never mix data.
///
/// Schema is versioned with PRAGMA user_version; [LocalDb.migrations] is append-only (never edit a shipped step).
class LocalDb {
  LocalDb._(this.raw);

  final Database raw;

  static LocalDb open(String path) {
    final db = LocalDb._(sqlite3.open(path));
    db._init();
    return db;
  }

  static LocalDb memory() {
    final db = LocalDb._(sqlite3.openInMemory());
    db._init();
    return db;
  }

  /// Each entry upgrades from version (index) to version (index + 1).
  static const List<String> migrations = [
    '''
    CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
    CREATE TABLE categories (id TEXT PRIMARY KEY, name TEXT NOT NULL);
    CREATE TABLE products (
      id TEXT PRIMARY KEY, category_id TEXT, name TEXT NOT NULL, sku TEXT, barcode TEXT, item_type TEXT NOT NULL,
      selling_price INTEGER NOT NULL, cost_price INTEGER, reorder_level INTEGER NOT NULL DEFAULT 5, is_active INTEGER NOT NULL DEFAULT 1
    );
    CREATE INDEX idx_products_name ON products (name COLLATE NOCASE);
    CREATE INDEX idx_products_barcode ON products (barcode);
    CREATE TABLE customer_groups (id TEXT PRIMARY KEY, name TEXT NOT NULL, discount_bp INTEGER NOT NULL DEFAULT 0);
    CREATE TABLE customers (
      id TEXT PRIMARY KEY, group_id TEXT, name TEXT NOT NULL, phone TEXT, email TEXT, address TEXT,
      loyalty_points INTEGER NOT NULL DEFAULT 0, total_spent INTEGER NOT NULL DEFAULT 0, local_only INTEGER NOT NULL DEFAULT 0
    );
    CREATE INDEX idx_customers_name ON customers (name COLLATE NOCASE);
    CREATE TABLE locations (id TEXT PRIMARY KEY, name TEXT NOT NULL, is_default INTEGER NOT NULL DEFAULT 0, timezone TEXT);
    CREATE TABLE stock_levels (product_id TEXT NOT NULL, location_id TEXT NOT NULL, quantity INTEGER NOT NULL, PRIMARY KEY (product_id, location_id));
    CREATE TABLE outbox (
      seq INTEGER PRIMARY KEY AUTOINCREMENT, id TEXT NOT NULL UNIQUE, type TEXT NOT NULL, payload TEXT NOT NULL, summary TEXT NOT NULL DEFAULT '',
      client_created_at TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', code TEXT, message TEXT, result TEXT,
      attempts INTEGER NOT NULL DEFAULT 0, next_attempt_at TEXT
    );
    CREATE INDEX idx_outbox_status ON outbox (status, seq);
    -- stock the device has promised but the server has not yet counted; shown as "available = server quantity - pending"
    CREATE TABLE outbox_stock (op_id TEXT NOT NULL, product_id TEXT NOT NULL, location_id TEXT NOT NULL, quantity INTEGER NOT NULL, PRIMARY KEY (op_id, product_id, location_id));
    ''',
  ];

  void _init() {
    raw.execute('PRAGMA foreign_keys = ON');
    final v = raw.userVersion;
    if (v > migrations.length) {
      throw StateError('This database was written by a newer version of the app (schema $v).');
    }
    for (var i = v; i < migrations.length; i++) {
      raw.execute('BEGIN');
      try {
        raw.execute(migrations[i]);
        raw.userVersion = i + 1;
        raw.execute('COMMIT');
      } catch (_) {
        raw.execute('ROLLBACK');
        rethrow;
      }
    }
  }

  int get schemaVersion => raw.userVersion;

  void close() => raw.close();

  int _depth = 0;

  /// Runs [body] atomically. Re-entrant: an inner call joins the outer transaction (and rolls back with it).
  T tx<T>(T Function() body) {
    if (_depth > 0) return body();
    raw.execute('BEGIN');
    _depth++;
    try {
      final r = body();
      raw.execute('COMMIT');
      return r;
    } catch (_) {
      raw.execute('ROLLBACK');
      rethrow;
    } finally {
      _depth--;
    }
  }

  // ---------------------------------------------------------------------------------------------- meta
  String? meta(String key) {
    final r = raw.select('SELECT value FROM meta WHERE key = ?', [key]);
    return r.isEmpty ? null : r.first['value'] as String;
  }

  void setMeta(String key, String? value) {
    if (value == null) {
      raw.execute('DELETE FROM meta WHERE key = ?', [key]);
    } else {
      raw.execute('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [key, value]);
    }
  }

  Map<String, dynamic> metaJson(String key) {
    final s = meta(key);
    if (s == null) return {};
    try {
      final d = jsonDecode(s);
      return d is Map<String, dynamic> ? d : {};
    } on FormatException {
      return {};
    }
  }

  void setMetaJson(String key, Map<String, dynamic> value) => setMeta(key, jsonEncode(value));

  // ---------------------------------------------------------------------------------------------- catalogue
  void upsertProduct(Map<String, dynamic> p) {
    raw.execute(
      '''INSERT INTO products (id, category_id, name, sku, barcode, item_type, selling_price, cost_price, reorder_level, is_active)
         VALUES (?,?,?,?,?,?,?,?,?,?)
         ON CONFLICT(id) DO UPDATE SET category_id=excluded.category_id, name=excluded.name, sku=excluded.sku, barcode=excluded.barcode,
           item_type=excluded.item_type, selling_price=excluded.selling_price, cost_price=excluded.cost_price, reorder_level=excluded.reorder_level, is_active=excluded.is_active''',
      [
        p['id'], p['category_id'], p['name'], p['sku'], p['barcode'], p['item_type'], _cents(p['selling_price']),
        p.containsKey('cost_price') && p['cost_price'] != null ? _cents(p['cost_price']) : null, (p['reorder_level'] as num?)?.toInt() ?? 5, _flag(p['is_active']),
      ],
    );
  }

  void deleteProduct(String id) {
    raw.execute('DELETE FROM products WHERE id = ?', [id]);
    raw.execute('DELETE FROM stock_levels WHERE product_id = ?', [id]);
  }

  void upsertCategory(Map<String, dynamic> c) =>
      raw.execute('INSERT INTO categories (id, name) VALUES (?,?) ON CONFLICT(id) DO UPDATE SET name=excluded.name', [c['id'], c['name']]);

  void upsertGroup(Map<String, dynamic> g) => raw.execute(
        'INSERT INTO customer_groups (id, name, discount_bp) VALUES (?,?,?) ON CONFLICT(id) DO UPDATE SET name=excluded.name, discount_bp=excluded.discount_bp',
        [g['id'], g['name'], (double.parse('${g['discount_percentage'] ?? 0}') * 100).round()],
      );

  void upsertCustomer(Map<String, dynamic> c) => raw.execute(
        '''INSERT INTO customers (id, group_id, name, phone, email, address, loyalty_points, total_spent, local_only) VALUES (?,?,?,?,?,?,?,?,0)
           ON CONFLICT(id) DO UPDATE SET group_id=excluded.group_id, name=excluded.name, phone=excluded.phone, email=excluded.email, address=excluded.address,
             loyalty_points=excluded.loyalty_points, total_spent=excluded.total_spent, local_only=0''',
        [c['id'], c['customer_group_id'], c['name'], c['phone'], c['email'], c['address'], (c['loyalty_points'] as num?)?.toInt() ?? 0, _cents(c['total_spent'] ?? '0')],
      );

  /// A customer created on this device that the server has not seen yet.
  void insertLocalCustomer({required String id, required String name, String? phone, String? email, String? groupId}) => raw.execute(
        'INSERT INTO customers (id, group_id, name, phone, email, local_only) VALUES (?,?,?,?,?,1) ON CONFLICT(id) DO NOTHING',
        [id, groupId, name, phone, email],
      );

  void deleteCustomer(String id) => raw.execute('DELETE FROM customers WHERE id = ?', [id]);

  void upsertLocation(Map<String, dynamic> l) => raw.execute(
        'INSERT INTO locations (id, name, is_default, timezone) VALUES (?,?,?,?) ON CONFLICT(id) DO UPDATE SET name=excluded.name, is_default=excluded.is_default, timezone=excluded.timezone',
        [l['id'], l['name'], _flag(l['is_default']), l['timezone']],
      );

  void deleteLocation(String id) {
    raw.execute('DELETE FROM locations WHERE id = ?', [id]);
    raw.execute('DELETE FROM stock_levels WHERE location_id = ?', [id]);
  }

  void upsertStockLevel(String productId, String locationId, int quantity) => raw.execute(
        'INSERT INTO stock_levels (product_id, location_id, quantity) VALUES (?,?,?) ON CONFLICT(product_id, location_id) DO UPDATE SET quantity=excluded.quantity',
        [productId, locationId, quantity],
      );

  /// Remove categories / groups that no longer exist on the server (they are never sent as tombstones).
  void pruneToIds(String table, List<String> keep) {
    assert(table == 'categories' || table == 'customer_groups');
    if (keep.isEmpty) {
      raw.execute('DELETE FROM $table');
      return;
    }
    final marks = List.filled(keep.length, '?').join(',');
    raw.execute('DELETE FROM $table WHERE id NOT IN ($marks)', keep);
  }

  // ---------------------------------------------------------------------------------------------- reads
  List<Map<String, Object?>> products({String? search, String? categoryId, int limit = 200}) {
    final where = <String>["is_active = 1"];
    final args = <Object?>[];
    if (search != null && search.trim().isNotEmpty) {
      where.add("(name LIKE ? ESCAPE '\\' OR sku LIKE ? ESCAPE '\\' OR barcode = ?)");
      final like = '%${search.trim().replaceAll('\\', '\\\\').replaceAll('%', '\\%').replaceAll('_', '\\_')}%';
      args..add(like)..add(like)..add(search.trim());
    }
    if (categoryId != null) {
      where.add('category_id = ?');
      args.add(categoryId);
    }
    return raw.select('SELECT * FROM products WHERE ${where.join(' AND ')} ORDER BY name COLLATE NOCASE LIMIT $limit', args).map((r) => Map<String, Object?>.from(r)).toList();
  }

  Map<String, Object?>? product(String id) {
    final r = raw.select('SELECT * FROM products WHERE id = ?', [id]);
    return r.isEmpty ? null : Map<String, Object?>.from(r.first);
  }

  List<Map<String, Object?>> customers({String? search, int limit = 200}) {
    final args = <Object?>[];
    var where = '1=1';
    if (search != null && search.trim().isNotEmpty) {
      where = "(name LIKE ? ESCAPE '\\' OR phone LIKE ? ESCAPE '\\')";
      final like = '%${search.trim().replaceAll('\\', '\\\\').replaceAll('%', '\\%').replaceAll('_', '\\_')}%';
      args..add(like)..add(like);
    }
    return raw
        .select('SELECT c.*, COALESCE(g.discount_bp, 0) AS discount_bp FROM customers c LEFT JOIN customer_groups g ON g.id = c.group_id WHERE $where ORDER BY c.name COLLATE NOCASE LIMIT $limit', args)
        .map((r) => Map<String, Object?>.from(r))
        .toList();
  }

  List<Map<String, Object?>> locations() => raw.select('SELECT * FROM locations ORDER BY is_default DESC, name').map((r) => Map<String, Object?>.from(r)).toList();

  /// What can still be sold from [locationId]: the server's number minus what this device has already promised in queued sales.
  int available(String productId, String locationId) {
    final q = raw.select('SELECT quantity FROM stock_levels WHERE product_id = ? AND location_id = ?', [productId, locationId]);
    final server = q.isEmpty ? 0 : q.first['quantity'] as int;
    return server - pendingQuantity(productId, locationId);
  }

  int pendingQuantity(String productId, String locationId) {
    final r = raw.select('SELECT COALESCE(SUM(quantity),0) AS q FROM outbox_stock WHERE product_id = ? AND location_id = ?', [productId, locationId]);
    return r.first['q'] as int;
  }

  // ---------------------------------------------------------------------------------------------- outbox
  /// Queue something for the server. The returned id is the operation id the server will treat as the idempotency key.
  void enqueue({
    required String id,
    required String type,
    required Map<String, dynamic> payload,
    required String summary,
    required DateTime now,
    List<({String productId, String locationId, int quantity})> stock = const [],
  }) {
    tx(() {
      raw.execute('INSERT INTO outbox (id, type, payload, summary, client_created_at) VALUES (?,?,?,?,?)', [id, type, jsonEncode(payload), summary, now.toUtc().toIso8601String()]);
      for (final s in stock) {
        raw.execute('INSERT INTO outbox_stock (op_id, product_id, location_id, quantity) VALUES (?,?,?,?)', [id, s.productId, s.locationId, s.quantity]);
      }
    });
  }

  List<Map<String, Object?>> outbox({List<String>? statuses, int limit = 500}) {
    final where = statuses == null ? '1=1' : 'status IN (${List.filled(statuses.length, '?').join(',')})';
    return raw.select('SELECT * FROM outbox WHERE $where ORDER BY seq LIMIT $limit', statuses ?? []).map((r) => Map<String, Object?>.from(r)).toList();
  }

  /// Operations that are due to be sent now, in the order the cashier did them.
  List<Map<String, Object?>> dueOperations(DateTime now, {int limit = 100}) => raw
      .select("SELECT * FROM outbox WHERE status IN ('pending','conflict') AND (next_attempt_at IS NULL OR next_attempt_at <= ?) ORDER BY seq LIMIT $limit", [now.toUtc().toIso8601String()])
      .map((r) => Map<String, Object?>.from(r))
      .toList();

  int count(String status) => raw.select('SELECT COUNT(*) AS n FROM outbox WHERE status = ?', [status]).first['n'] as int;

  void setOperationOutcome(String id, {required String status, String? code, String? message, Map<String, dynamic>? result, DateTime? nextAttempt, bool countAttempt = false}) {
    raw.execute(
      'UPDATE outbox SET status = ?, code = ?, message = ?, result = COALESCE(?, result), next_attempt_at = ?, attempts = attempts + ? WHERE id = ?',
      [status, code, message, result == null ? null : jsonEncode(result), nextAttempt?.toUtc().toIso8601String(), countAttempt ? 1 : 0, id],
    );
    if (status == 'rejected' || status == 'discarded') {
      // a sale that will never be recorded no longer promises stock
      raw.execute('DELETE FROM outbox_stock WHERE op_id = ?', [id]);
    }
  }

  /// After a successful pull the server's numbers include every applied operation: forget them and their stock promises.
  int purgeApplied() {
    return tx(() {
      raw.execute("DELETE FROM outbox_stock WHERE op_id IN (SELECT id FROM outbox WHERE status = 'applied')");
      raw.execute("DELETE FROM outbox WHERE status IN ('applied','discarded')");
      return raw.updatedRows;
    });
  }

  static int _cents(Object? v) => v is num ? (v * 100).round() : _parseCents('$v');
  static int _flag(Object? v) => (v == true || v == 1 || v == '1') ? 1 : 0;

  static int _parseCents(String s) {
    final m = RegExp(r'^(-?)(\d+)(?:\.(\d{1,2}))?$').firstMatch(s.trim());
    if (m == null) throw FormatException('Not an amount: "$s"');
    final v = int.parse(m.group(2)!) * 100 + int.parse((m.group(3) ?? '').padRight(2, '0'));
    return m.group(1) == '-' ? -v : v;
  }
}
