import axios from "axios";
import { React, useCallback, useEffect, useState } from "react";
import { Alert, Badge, Button, Card, Col, Form, Modal, Row, Table } from "react-bootstrap";

const WEEKDAYS = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
const PROVIDERS = [
  ["sftp", "SFTP / SSH server"],
  ["webdav", "WebDAV / Nextcloud"],
  ["filesystem", "Mounted NAS / filesystem"],
  ["s3", "S3-compatible / MinIO"],
  ["from-config", "Kopia configuration token"],
  ["rclone", "Rclone remote (advanced)"],
];
const SCHEDULES = [
  ["manual", "Manual only"],
  ["hourly", "Hourly"],
  ["every6h", "Every 6 hours"],
  ["every12h", "Every 12 hours"],
  ["daily", "Daily (every day)"],
  ["weekly", "Weekly"],
];

function emptyReplica() {
  return {
    id: "",
    name: "",
    enabled: true,
    provider: "sftp",
    schedule: "every6h",
    time: "03:30",
    weekday: 0,
    parallel: 1,
    config: {
      host: "",
      port: 22,
      username: "",
      path: "",
      auth_mode: "key",
      url: "",
      endpoint: "",
      bucket: "",
      region: "",
      prefix: "",
      disable_tls: false,
      remote_path: "",
    },
    secret: {
      password: "",
      private_key: "",
      known_hosts: "",
      access_key: "",
      secret_access_key: "",
      session_token: "",
      token: "",
      rclone_config: "",
    },
    secret_configured: {},
  };
}

function errorText(error) {
  return error?.response?.data?.error || error?.message || "Request failed.";
}

function providerLabel(value) {
  return PROVIDERS.find(([key]) => key === value)?.[1] || value;
}

function scheduleLabel(replica) {
  const label = SCHEDULES.find(([key]) => key === replica.schedule)?.[1] || replica.schedule;
  if (replica.schedule === "daily") return `${label} at ${replica.time || "03:30"}`;
  if (replica.schedule === "weekly") return `${WEEKDAYS[Number(replica.weekday) || 0]} at ${replica.time || "03:30"}`;
  return label;
}

function statusBadge(replica) {
  if (!replica.enabled) return <Badge bg="secondary">Disabled</Badge>;
  if (replica.last_status === "success") return <Badge bg="success">Success</Badge>;
  if (replica.last_status === "failed") return <Badge bg="danger">Failed</Badge>;
  return <Badge bg="secondary">Not run</Badge>;
}

export function Replication() {
  const [replicas, setReplicas] = useState([]);
  const [source, setSource] = useState({ connected: false, config_present: false, password_configured: false, detail: "Loading…" });
  const [loading, setLoading] = useState(true);
  const [pageError, setPageError] = useState("");
  const [showEditor, setShowEditor] = useState(false);
  const [form, setForm] = useState(emptyReplica());
  const [working, setWorking] = useState(false);
  const [testResult, setTestResult] = useState("");
  const [editorError, setEditorError] = useState("");
  const [sftpSetup, setSftpSetup] = useState({
    current_password: "",
    change_password: false,
    new_password: "",
    new_password_confirm: "",
    generate_keypair: true,
    public_key: "",
    fingerprint: "",
  });

  const refresh = useCallback(async () => {
    try {
      const result = await axios.get("everlomp-api/replicas");
      setReplicas(result.data.replicas || []);
      setSource(result.data.source || {});
      setPageError("");
    } catch (error) {
      setPageError(errorText(error));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    refresh();
    const timer = window.setInterval(refresh, 15000);
    return () => window.clearInterval(timer);
  }, [refresh]);

  function openNew() {
    setForm(emptyReplica());
    setTestResult("");
    setEditorError("");
    setSftpSetup({ current_password: "", change_password: false, new_password: "", new_password_confirm: "", generate_keypair: true, public_key: "", fingerprint: "" });
    setShowEditor(true);
  }

  function openEdit(replica) {
    const fresh = emptyReplica();
    setForm({
      ...fresh,
      ...replica,
      config: { ...fresh.config, ...(replica.config || {}) },
      secret: { ...fresh.secret },
      secret_configured: replica.secret_configured || {},
    });
    setTestResult("");
    setEditorError("");
    setSftpSetup({ current_password: "", change_password: false, new_password: "", new_password_confirm: "", generate_keypair: true, public_key: "", fingerprint: "" });
    setShowEditor(true);
  }

  function setField(name, value) {
    setForm((old) => ({ ...old, [name]: value }));
  }

  function setConfig(name, value) {
    setForm((old) => ({ ...old, config: { ...old.config, [name]: value } }));
  }

  function setSecret(name, value) {
    setForm((old) => ({ ...old, secret: { ...old.secret, [name]: value } }));
  }

  function requestPayload() {
    return {
      id: form.id || undefined,
      name: form.name,
      enabled: form.enabled,
      provider: form.provider,
      schedule: form.schedule,
      time: form.time,
      weekday: Number(form.weekday),
      parallel: Number(form.parallel),
      config: form.config,
      secret: form.secret,
    };
  }

  async function fetchHostKey() {
    setWorking(true);
    setTestResult("");
    setEditorError("");
    try {
      const result = await axios.post("everlomp-api/sftp-host-key", {
        host: form.config.host,
        port: Number(form.config.port || 22),
      });
      const knownHosts = result.data.known_hosts || "";
      setSecret("known_hosts", knownHosts);
      setTestResult("SSH host key fetched. It will be saved with this replica.");
    } catch (error) {
      setEditorError(errorText(error));
    } finally {
      setWorking(false);
    }
  }

  function generateRemotePassword() {
    if (!window.crypto?.getRandomValues) {
      setEditorError("Secure password generation is not available in this browser.");
      return;
    }
    const alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%_-+=?";
    const bytes = new Uint8Array(24);
    window.crypto.getRandomValues(bytes);
    let password = "";
    for (const value of bytes) password += alphabet[value % alphabet.length];
    setSftpSetup((old) => ({ ...old, new_password: password, new_password_confirm: password, change_password: true }));
  }

  async function bootstrapSftpAccess() {
    setWorking(true);
    setTestResult("");
    setEditorError("");
    try {
      if (!form.config.host || !form.config.username) throw new Error("Enter the SFTP host and username first.");
      if (!sftpSetup.current_password) throw new Error("Enter the current remote SSH password.");
      if (sftpSetup.change_password && sftpSetup.new_password !== sftpSetup.new_password_confirm) throw new Error("The new remote SSH passwords do not match.");

      const result = await axios.post("everlomp-api/sftp-bootstrap", {
        host: form.config.host,
        port: Number(form.config.port || 22),
        username: form.config.username,
        current_password: sftpSetup.current_password,
        known_hosts: form.secret.known_hosts,
        change_password: !!sftpSetup.change_password,
        new_password: sftpSetup.new_password,
        new_password_confirm: sftpSetup.new_password_confirm,
        generate_keypair: !!sftpSetup.generate_keypair,
      });
      const data = result.data || {};
      if (data.known_hosts) setSecret("known_hosts", data.known_hosts);
      if (data.keypair_generated && data.private_key) {
        setConfig("auth_mode", "key");
        setSecret("private_key", data.private_key);
        setSecret("password", "");
      } else {
        setConfig("auth_mode", "password");
        setSecret("password", sftpSetup.change_password ? sftpSetup.new_password : sftpSetup.current_password);
      }
      setSftpSetup((old) => ({
        ...old,
        current_password: "",
        public_key: data.public_key || "",
        fingerprint: data.fingerprint || "",
      }));
      setTestResult((data.detail || "SSH access configured successfully.") + " Save the replica to persist the generated credentials for scheduled Kopia syncs.");
    } catch (error) {
      setEditorError(errorText(error));
    } finally {
      setWorking(false);
    }
  }

  async function testConnection() {
    setWorking(true);
    setTestResult("");
    setEditorError("");
    try {
      const result = await axios.post("everlomp-api/replicas/test", requestPayload());
      setTestResult(result.data.detail || "Connection test succeeded.");
    } catch (error) {
      setEditorError(errorText(error));
    } finally {
      setWorking(false);
    }
  }

  async function saveReplica() {
    setWorking(true);
    setEditorError("");
    try {
      await axios.post("everlomp-api/replicas", requestPayload());
      setShowEditor(false);
      await refresh();
    } catch (error) {
      setEditorError(errorText(error));
    } finally {
      setWorking(false);
    }
  }

  async function syncNow(replica) {
    setPageError("");
    try {
      await axios.post(`everlomp-api/replicas/${replica.id}/sync`, {});
      await refresh();
    } catch (error) {
      setPageError(errorText(error));
    }
  }

  async function removeReplica(replica) {
    if (!window.confirm(`Remove replica “${replica.name}”? The remote repository data itself will not be deleted.`)) return;
    setPageError("");
    try {
      await axios.delete(`everlomp-api/replicas/${replica.id}`, { data: {} });
      await refresh();
    } catch (error) {
      setPageError(errorText(error));
    }
  }


  const needsTime = form.schedule === "daily" || form.schedule === "weekly";
  const needsWeekday = form.schedule === "weekly";
  const saved = form.secret_configured || {};

  return (
    <div className="pb-4">
      <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
          <h4 className="mb-1">Repository Replication</h4>
          <div className="text-muted">Keep additional copies of the currently connected Kopia repository.</div>
        </div>
        <Button onClick={openNew} disabled={!source.connected}>Add Replica</Button>
      </div>

      {pageError && <Alert variant="danger">{pageError}</Alert>}

      {!source.config_present ? (
        <Alert variant="info">
          No primary repository is connected yet. Open <strong>Repository</strong>, create or connect your primary repository, then return here to add replicas.
        </Alert>
      ) : source.connected ? (
        <Alert variant="success" className="py-2">
          Primary repository is available to the replication worker.
        </Alert>
      ) : (
        <Alert variant="warning">
          <div className="mb-2"><strong>The background replication worker cannot open the primary repository.</strong></div>
          <div>{source.detail || "The primary repository password needs attention."} Open <strong>Repository → Repository Password</strong> to save or verify it.</div>
        </Alert>
      )}

      <Card>
        <Card.Body>
          {loading ? (
            <div>Loading replicas…</div>
          ) : replicas.length === 0 ? (
            <div className="text-muted">No secondary replicas configured. Your primary repository continues working normally.</div>
          ) : (
            <Table responsive hover className="mb-0 align-middle">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Destination</th>
                  <th>Schedule</th>
                  <th>Status</th>
                  <th>Last run</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {replicas.map((replica) => (
                  <tr key={replica.id}>
                    <td><strong>{replica.name}</strong></td>
                    <td>{providerLabel(replica.provider)}</td>
                    <td>{scheduleLabel(replica)}</td>
                    <td>{statusBadge(replica)}</td>
                    <td title={replica.last_detail || ""}>{replica.last_run ? new Date(replica.last_run).toLocaleString() : "Never"}</td>
                    <td className="text-nowrap">
                      <Button size="sm" variant="primary" className="me-1 fw-semibold" onClick={() => syncNow(replica)} disabled={!source.connected}>Sync Now</Button>
                      <Button size="sm" variant="secondary" className="me-1 fw-semibold" onClick={() => openEdit(replica)}>Edit</Button>
                      <Button size="sm" variant="danger" className="fw-semibold" onClick={() => removeReplica(replica)}>Remove</Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </Card.Body>
      </Card>

      <div className="text-muted mt-3 small">
        Replication uses Kopia&apos;s native repository synchronization. Everlomp does not request destination deletions during synchronization.
      </div>

      <Modal show={showEditor} onHide={() => !working && setShowEditor(false)} size="lg" centered>
        <Modal.Header closeButton><Modal.Title>{form.id ? "Edit Replica" : "Add Replica"}</Modal.Title></Modal.Header>
        <Modal.Body>
          {editorError && <Alert variant="danger">{editorError}</Alert>}
          {testResult && <Alert variant="success">{testResult}</Alert>}

          <Row className="g-3">
            <Col md={8}>
              <Form.Label>Name</Form.Label>
              <Form.Control value={form.name} onChange={(e) => setField("name", e.target.value)} placeholder="Off-site backup" />
            </Col>
            <Col md={4} className="d-flex align-items-end">
              <Form.Check type="switch" id="replica-enabled" label="Automatic sync enabled" checked={!!form.enabled} onChange={(e) => setField("enabled", e.target.checked)} className="mb-2" />
            </Col>
            <Col md={6}>
              <Form.Label>Destination type</Form.Label>
              <Form.Select value={form.provider} onChange={(e) => setField("provider", e.target.value)}>
                {PROVIDERS.map(([value, label]) => <option value={value} key={value}>{label}</option>)}
              </Form.Select>
            </Col>
            <Col md={6}>
              <Form.Label>Schedule</Form.Label>
              <Form.Select value={form.schedule} onChange={(e) => setField("schedule", e.target.value)}>
                {SCHEDULES.map(([value, label]) => <option value={value} key={value}>{label}</option>)}
              </Form.Select>
            </Col>
            {needsTime && <Col md={needsWeekday ? 6 : 12}>
              <Form.Label>Time (container local time)</Form.Label>
              <Form.Control type="time" value={form.time} onChange={(e) => setField("time", e.target.value)} />
            </Col>}
            {needsWeekday && <Col md={6}>
              <Form.Label>Day of week</Form.Label>
              <Form.Select value={form.weekday} onChange={(e) => setField("weekday", Number(e.target.value))}>
                {WEEKDAYS.map((day, index) => <option value={index} key={day}>{day}</option>)}
              </Form.Select>
            </Col>}
            <Col md={6}>
              <Form.Label>Parallel transfers</Form.Label>
              <Form.Control type="number" min="1" max="32" value={form.parallel} onChange={(e) => setField("parallel", Number(e.target.value))} />
            </Col>
          </Row>

          <hr />

          {form.provider === "sftp" && <Row className="g-3">
            <Col md={8}><Form.Label>Host</Form.Label><Form.Control value={form.config.host} onChange={(e) => setConfig("host", e.target.value)} placeholder="backup.example.net" /></Col>
            <Col md={4}><Form.Label>Port</Form.Label><Form.Control type="number" min="1" max="65535" value={form.config.port} onChange={(e) => setConfig("port", Number(e.target.value))} /></Col>
            <Col md={6}><Form.Label>Username</Form.Label><Form.Control value={form.config.username} onChange={(e) => setConfig("username", e.target.value)} /></Col>
            <Col md={6}><Form.Label>Remote path</Form.Label><Form.Control value={form.config.path} onChange={(e) => setConfig("path", e.target.value)} placeholder="/srv/backups/everlomp" /></Col>

            <Col xs={12}>
              <Card className="border-primary">
                <Card.Body>
                  <Card.Title className="h6">Remote SSH account setup</Card.Title>
                  <Card.Text className="text-muted small">Use the account&apos;s current password once to change its password, install a new Everlomp ED25519 public key, or both. The current password is never saved by this setup action.</Card.Text>
                  <Row className="g-3">
                    <Col md={6}><Form.Label>Current remote SSH password</Form.Label><Form.Control type="password" value={sftpSetup.current_password} onChange={(e) => setSftpSetup((old) => ({ ...old, current_password: e.target.value }))} autoComplete="current-password" /></Col>
                    <Col md={6} className="d-flex align-items-end"><Form.Check type="switch" id="sftp-generate-keypair" label="Generate & install SSH keypair" checked={!!sftpSetup.generate_keypair} onChange={(e) => setSftpSetup((old) => ({ ...old, generate_keypair: e.target.checked }))} className="mb-2" /></Col>
                    <Col xs={12}><Form.Check type="switch" id="sftp-change-password" label="Change the remote account password too" checked={!!sftpSetup.change_password} onChange={(e) => setSftpSetup((old) => ({ ...old, change_password: e.target.checked }))} /></Col>
                    {sftpSetup.change_password && <>
                      <Col md={5}><Form.Label>New remote password</Form.Label><Form.Control type="password" value={sftpSetup.new_password} onChange={(e) => setSftpSetup((old) => ({ ...old, new_password: e.target.value }))} autoComplete="new-password" /></Col>
                      <Col md={5}><Form.Label>Confirm new password</Form.Label><Form.Control type="password" value={sftpSetup.new_password_confirm} onChange={(e) => setSftpSetup((old) => ({ ...old, new_password_confirm: e.target.value }))} autoComplete="new-password" /></Col>
                      <Col md={2} className="d-flex align-items-end"><Button type="button" variant="primary" className="w-100 fw-semibold" onClick={generateRemotePassword}>Generate</Button></Col>
                    </>}
                    <Col xs={12} className="d-flex flex-wrap gap-2 align-items-center">
                      <Button type="button" variant="success" className="fw-semibold" onClick={bootstrapSftpAccess} disabled={working || !form.config.host || !form.config.username || !sftpSetup.current_password}>{working ? "Working…" : "Apply SSH Setup"}</Button>
                      <span className="text-muted small">Requires normal SSH shell access on the backup account. SFTP-only/restricted accounts may not permit password changes or authorized_keys installation.</span>
                    </Col>
                    {sftpSetup.public_key && <Col xs={12}><Form.Label>Installed public key</Form.Label><Form.Control as="textarea" rows={2} readOnly value={sftpSetup.public_key} /><Form.Text>{sftpSetup.fingerprint || "Key login verified."}</Form.Text></Col>}
                  </Row>
                </Card.Body>
              </Card>
            </Col>

            <Col md={6}><Form.Label>Kopia authentication</Form.Label><Form.Select value={form.config.auth_mode || "key"} onChange={(e) => setConfig("auth_mode", e.target.value)}><option value="key">SSH private key</option><option value="password">Password</option></Form.Select></Col>
            {form.config.auth_mode === "password" ? <Col md={6}><Form.Label>Password used by Kopia</Form.Label><Form.Control type="password" value={form.secret.password} onChange={(e) => setSecret("password", e.target.value)} placeholder={saved.password ? "Saved — leave blank to keep" : ""} /></Col> : <Col xs={12}><Form.Label>SSH private key used by Kopia</Form.Label><Form.Control as="textarea" rows={5} value={form.secret.private_key} onChange={(e) => setSecret("private_key", e.target.value)} placeholder={saved.private_key ? "Saved — leave blank to keep" : "Use Apply SSH Setup to generate/install one, or paste an existing private key"} /><Form.Text>Generated private keys are saved root-only with this replica when you click Save Replica. Kopia receives the key via its native SFTP keyfile option during sync.</Form.Text></Col>}
            <Col xs={12}>
              <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <Form.Label className="mb-0">SSH host key / known_hosts</Form.Label>
                {form.config.auth_mode === "password" && <Button size="sm" variant="primary" className="fw-semibold" onClick={fetchHostKey} disabled={working || !form.config.host}>{working ? "Working…" : "Fetch Host Key"}</Button>}
              </div>
              <Form.Control as="textarea" rows={3} value={form.secret.known_hosts} onChange={(e) => setSecret("known_hosts", e.target.value)} placeholder={saved.known_hosts ? "Saved — leave blank to keep" : (form.config.auth_mode === "key" ? "Captured automatically during key setup/test" : "Fetch it or let the connection test capture it") } />
              <Form.Text>{form.config.auth_mode === "key" ? "Key setup/test pins the destination host key automatically; no separate fetch step is needed." : "Everlomp pins the destination SSH host key and Kopia uses it for every SFTP sync."}</Form.Text>
            </Col>
          </Row>}

          {form.provider === "webdav" && <Row className="g-3">
            <Col xs={12}><Form.Label>WebDAV URL</Form.Label><Form.Control value={form.config.url} onChange={(e) => setConfig("url", e.target.value)} placeholder="https://cloud.example.net/remote.php/dav/files/user/backups/" /></Col>
            <Col md={6}><Form.Label>Username</Form.Label><Form.Control value={form.config.username} onChange={(e) => setConfig("username", e.target.value)} /></Col>
            <Col md={6}><Form.Label>Password</Form.Label><Form.Control type="password" value={form.secret.password} onChange={(e) => setSecret("password", e.target.value)} placeholder={saved.password ? "Saved — leave blank to keep" : ""} /></Col>
          </Row>}

          {form.provider === "filesystem" && <Row className="g-3">
            <Col xs={12}><Form.Label>Destination path inside container</Form.Label><Form.Control value={form.config.path} onChange={(e) => setConfig("path", e.target.value)} placeholder="/remote-backup/kopia" /><Form.Text>For NFS/SMB/NAS, mount it into the container first. Everlomp will not create a missing mount path.</Form.Text></Col>
          </Row>}

          {form.provider === "s3" && <Row className="g-3">
            <Col md={6}><Form.Label>Endpoint</Form.Label><Form.Control value={form.config.endpoint} onChange={(e) => setConfig("endpoint", e.target.value)} placeholder="minio.example.net:9000" /></Col>
            <Col md={6}><Form.Label>Bucket</Form.Label><Form.Control value={form.config.bucket} onChange={(e) => setConfig("bucket", e.target.value)} /></Col>
            <Col md={6}><Form.Label>Region</Form.Label><Form.Control value={form.config.region} onChange={(e) => setConfig("region", e.target.value)} placeholder="us-east-1" /></Col>
            <Col md={6}><Form.Label>Prefix</Form.Label><Form.Control value={form.config.prefix} onChange={(e) => setConfig("prefix", e.target.value)} placeholder="server-01/" /></Col>
            <Col md={6}><Form.Label>Access key</Form.Label><Form.Control type="password" value={form.secret.access_key} onChange={(e) => setSecret("access_key", e.target.value)} placeholder={saved.access_key ? "Saved — leave blank to keep" : ""} /></Col>
            <Col md={6}><Form.Label>Secret key</Form.Label><Form.Control type="password" value={form.secret.secret_access_key} onChange={(e) => setSecret("secret_access_key", e.target.value)} placeholder={saved.secret_access_key ? "Saved — leave blank to keep" : ""} /></Col>
            <Col xs={12}><Form.Label>Session token (optional)</Form.Label><Form.Control type="password" value={form.secret.session_token} onChange={(e) => setSecret("session_token", e.target.value)} placeholder={saved.session_token ? "Saved — leave blank to keep" : ""} /></Col>
            <Col xs={12}><Form.Check type="switch" id="replica-s3-disable-tls" label="Disable TLS (only for trusted local MinIO/testing)" checked={!!form.config.disable_tls} onChange={(e) => setConfig("disable_tls", e.target.checked)} /></Col>
          </Row>}

          {form.provider === "from-config" && <Row className="g-3">
            <Col xs={12}><Form.Label>Kopia configuration / quick-reconnect token</Form.Label><Form.Control as="textarea" rows={5} value={form.secret.token} onChange={(e) => setSecret("token", e.target.value)} placeholder={saved.token ? "Saved — leave blank to keep" : "03Fy…"} /><Form.Text>Treat this token like a password.</Form.Text></Col>
          </Row>}

          {form.provider === "rclone" && <Row className="g-3">
            <Col xs={12}><Alert variant="warning" className="py-2">Advanced fallback for storage not covered by Kopia&apos;s native destinations.</Alert></Col>
            <Col xs={12}><Form.Label>Remote path</Form.Label><Form.Control value={form.config.remote_path} onChange={(e) => setConfig("remote_path", e.target.value)} placeholder="remote:backups/everlomp" /></Col>
            <Col xs={12}><Form.Label>rclone.conf</Form.Label><Form.Control as="textarea" rows={7} value={form.secret.rclone_config} onChange={(e) => setSecret("rclone_config", e.target.value)} placeholder={saved.rclone_config ? "Saved — leave blank to keep" : "[remote]\ntype = …"} /></Col>
          </Row>}
        </Modal.Body>
        <Modal.Footer>
          <Button variant="primary" className="fw-semibold" onClick={testConnection} disabled={working || !source.connected}>{working ? "Working…" : (form.provider === "sftp" ? (form.config.auth_mode === "key" ? "Test Key Connection" : "Test Password Connection") : "Test Connection")}</Button>
          <Button variant="secondary" className="fw-semibold" onClick={() => setShowEditor(false)} disabled={working}>Cancel</Button>
          <Button variant="success" className="fw-semibold" onClick={saveReplica} disabled={working}>{working ? "Saving…" : "Save Replica"}</Button>
        </Modal.Footer>
      </Modal>
    </div>
  );
}
