// SMTP server simulated for the specs that send an email (the test email of the
// Settings tab): clear connection, no authentication, every message kept in memory.
// The recipients refused@... are refused (550), as tests/fixtures/fake-smtp.php does.
const net = require('net');

/**
 * Starts the fake SMTP server on a free port of 127.0.0.1
 * @returns {Promise<{port: number, messages: Array<{from: string, to: string[], data: string}>, close: Function}>}
 */
function startFakeSmtp() {
    const messages = [];
    const sockets = new Set();
    const server = net.createServer(socket => {
        sockets.add(socket);
        socket.on('close', () => sockets.delete(socket));
        socket.on('error', () => {});
        socket.setEncoding('utf8');
        const reply = text => socket.write(text + '\r\n');
        let message = { from: null, to: [], data: '' };
        let inData = false;
        let buffer = '';

        reply('220 fake.smtp ESMTP ready');
        socket.on('data', chunk => {
            buffer += chunk;
            let index;
            while ((index = buffer.indexOf('\r\n')) >= 0) {
                const line = buffer.slice(0, index);
                buffer = buffer.slice(index + 2);
                if (inData) {
                    if (line === '.') {
                        inData = false;
                        messages.push(message);
                        message = { from: null, to: [], data: '' };
                        reply('250 2.0.0 Ok: queued');
                    } else {
                        message.data += line.replace(/^\./, '') + '\r\n';
                    }
                    continue;
                }
                const verb = line.split(/[ :]/)[0].toUpperCase();
                if (verb === 'EHLO') {
                    reply('250-fake.smtp\r\n250 8BITMIME');
                } else if (verb === 'MAIL') {
                    message.from = line.replace(/^MAIL FROM:<(.*)>.*$/i, '$1');
                    reply('250 2.1.0 Ok');
                } else if (verb === 'RCPT') {
                    const to = line.replace(/^RCPT TO:<(.*)>.*$/i, '$1');
                    if (to.startsWith('refused@')) {
                        reply('550 5.1.1 Mailbox unavailable');
                    } else {
                        message.to.push(to);
                        reply('250 2.1.5 Ok');
                    }
                } else if (verb === 'DATA') {
                    inData = true;
                    reply('354 End data with <CR><LF>.<CR><LF>');
                } else if (verb === 'QUIT') {
                    reply('221 2.0.0 Bye');
                    socket.end();
                } else {
                    reply('502 5.5.2 Command not recognized');
                }
            }
        });
    });

    return new Promise(resolve => {
        server.listen(0, '127.0.0.1', () => {
            resolve({
                port: server.address().port,
                messages,
                close: () => new Promise(done => {
                    sockets.forEach(socket => socket.destroy());
                    server.close(() => done());
                }),
            });
        });
    });
}

module.exports = { startFakeSmtp };
