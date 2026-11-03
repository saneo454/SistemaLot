const express = require('express');
const cors = require('cors');
const http = require('http');
const socketIo = require('socket.io');
const fs = require('fs');
const path = require('path');
const os = require('os');

const app = express();
const server = http.createServer(app);
const io = socketIo(server, {
  cors: {
    origin: '*',
    methods: ['GET', 'POST']
  }
});

const PORT = process.env.PORT || 3000;

// Path to JSON data files
const DATA_DIR = path.join(__dirname, 'data');
const USERS_FILE = path.join(DATA_DIR, 'users.json');
const ATTENDANCE_FILE = path.join(DATA_DIR, 'attendance.json');

// Ensure data directory and files exist
if (!fs.existsSync(DATA_DIR)) {
  fs.mkdirSync(DATA_DIR);
}
if (!fs.existsSync(USERS_FILE)) {
  fs.writeFileSync(USERS_FILE, JSON.stringify([
    { id: "12345", name: "Juan Pérez", team: "Equipo 1", role: "Estudiante" },
    { id: "ABCDE123", name: "María Gómez", team: "Equipo 2", role: "Estudiante" },
    { id: "54321", name: "Carlos Ruiz", team: "Equipo 3", role: "Profesor" }
  ], null, 2));
}
if (!fs.existsSync(ATTENDANCE_FILE)) {
  fs.writeFileSync(ATTENDANCE_FILE, JSON.stringify([], null, 2));
}

// Middleware
app.use(cors());
app.use(express.json());
app.use(express.urlencoded({ extended: true }));
app.use(express.static(path.join(__dirname, 'public')));

// Helper to read/write JSON
function readJSON(filePath) {
  try {
    const data = fs.readFileSync(filePath, 'utf8');
    return JSON.parse(data);
  } catch (err) {
    console.error(`Error reading file ${filePath}:`, err);
    return [];
  }
}

function writeJSON(filePath, data) {
  try {
    fs.writeFileSync(filePath, JSON.stringify(data, null, 2));
  } catch (err) {
    console.error(`Error writing file ${filePath}:`, err);
  }
}

// API Routes

// 1. Receive attendance from ESP32 or manual entry
app.post('/api/attendance', (req, res) => {
  const { id, type, team } = req.body;
  
  if (!id) {
    return res.status(400).json({ success: false, error: 'ID is required' });
  }

  const cleanId = id.toString().trim().toUpperCase();
  const cleanType = (type || 'RFID').toString().trim().toUpperCase();
  const cleanTeam = (team || 'Desconocido').toString().trim();

  // Read registered users
  const users = readJSON(USERS_FILE);
  const user = users.find(u => u.id.toUpperCase() === cleanId);

  // Read existing attendance
  const attendance = readJSON(ATTENDANCE_FILE);

  const timestamp = new Date();
  const newRecord = {
    id: cleanId,
    name: user ? user.name : 'Usuario No Registrado',
    role: user ? user.role : 'Invitado',
    type: cleanType, // 'RFID' or 'TECLADO'
    team: user ? user.team : cleanTeam, // If user exists, use their registered team, else use the reporting team
    reportingTeam: cleanTeam, // The team/device that registered this scan
    timestamp: timestamp.toISOString()
  };

  // Add to front of history
  attendance.unshift(newRecord);
  writeJSON(ATTENDANCE_FILE, attendance);

  // Emit event via socket.io for real-time dashboard update
  io.emit('new-attendance', newRecord);

  console.log(`[ATTENDANCE] ID: ${cleanId} | Name: ${newRecord.name} | Type: ${cleanType} | From: ${cleanTeam}`);

  return res.json({
    success: true,
    userRegistered: !!user,
    userName: newRecord.name,
    timestamp: newRecord.timestamp
  });
});

// 2. Get attendance history
app.get('/api/attendance', (req, res) => {
  res.json(readJSON(ATTENDANCE_FILE));
});

// 3. Clear attendance history
app.post('/api/attendance/clear', (req, res) => {
  writeJSON(ATTENDANCE_FILE, []);
  io.emit('clear-attendance');
  console.log('[SYSTEM] Attendance history cleared');
  res.json({ success: true });
});

// 4. Get registered users
app.get('/api/users', (req, res) => {
  res.json(readJSON(USERS_FILE));
});

// 5. Add or update user
app.post('/api/users', (req, res) => {
  const { id, name, team, role } = req.body;
  if (!id || !name) {
    return res.status(400).json({ success: false, error: 'ID and Name are required' });
  }

  const cleanId = id.toString().trim().toUpperCase();
  const users = readJSON(USERS_FILE);
  
  const existingIndex = users.findIndex(u => u.id.toUpperCase() === cleanId);
  const newUser = {
    id: cleanId,
    name: name.toString().trim(),
    team: (team || 'Sin Equipo').toString().trim(),
    role: (role || 'Estudiante').toString().trim()
  };

  if (existingIndex !== -1) {
    users[existingIndex] = newUser;
  } else {
    users.push(newUser);
  }

  writeJSON(USERS_FILE, users);
  io.emit('users-updated', users);
  console.log(`[USER] Registered/Updated ID: ${cleanId} as "${newUser.name}"`);
  res.json({ success: true, user: newUser });
});

// 6. Delete user
app.delete('/api/users/:id', (req, res) => {
  const userId = req.params.id.toUpperCase();
  const users = readJSON(USERS_FILE);
  const filteredUsers = users.filter(u => u.id.toUpperCase() !== userId);
  
  if (users.length === filteredUsers.length) {
    return res.status(404).json({ success: false, error: 'User not found' });
  }

  writeJSON(USERS_FILE, filteredUsers);
  io.emit('users-updated', filteredUsers);
  console.log(`[USER] Deleted ID: ${userId}`);
  res.json({ success: true });
});

// Socket connection
io.on('connection', (socket) => {
  console.log(`[SOCKET] Client connected: ${socket.id}`);
  
  // Send current data on connection
  socket.emit('initial-data', {
    attendance: readJSON(ATTENDANCE_FILE),
    users: readJSON(USERS_FILE)
  });

  socket.on('disconnect', () => {
    console.log(`[SOCKET] Client disconnected: ${socket.id}`);
  });
});

// Start Server
server.listen(PORT, '0.0.0.0', () => {
  console.log('==================================================');
  console.log(`   ESP32 ATTENDANCE SERVER RUNNING ON PORT ${PORT}`);
  console.log('==================================================');
  console.log('To access this dashboard or send data, use these URLs:');
  console.log(`- Local on this PC: http://localhost:${PORT}`);
  
  // List all network interfaces to show local network IP
  const interfaces = os.networkInterfaces();
  for (const name of Object.keys(interfaces)) {
    for (const net of interfaces[name]) {
      // Select IPv4 and non-loopback
      if (net.family === 'IPv4' && !net.internal) {
        console.log(`- Local Network IP: http://${net.address}:${PORT}`);
      }
    }
  }
  console.log('==================================================');
});
