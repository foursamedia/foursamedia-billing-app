<footer class="mt-auto py-4">
    <div class="container text-center">
        <p class="mb-0">© 2023 FOURSAMEDIA. All rights reserved.</p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const sidebarToggle = document.getElementById('sidebarToggle');
        const wrapper = document.getElementById('wrapper');

        if (sidebarToggle && wrapper) {
            sidebarToggle.addEventListener('click', function() {
                wrapper.classList.toggle('toggled');
            });
        }

        // --- JavaScript for Notifications ---
        const notificationBell = document.getElementById('navbarDropdown');
        const notificationBadge = notificationBell ? notificationBell.querySelector('.notification-badge') : null;
        const notificationDropdownMenu = notificationBell ? notificationBell.nextElementSibling : null;

        function updateNotificationsDisplay(data) {
            if (notificationBadge) {
                if (data.count > 0) {
                    notificationBadge.textContent = data.count;
                    notificationBadge.style.display = ''; // Show badge
                } else {
                    notificationBadge.style.display = 'none'; // Hide badge
                }
            }

            if (notificationDropdownMenu) {
                let dropdownHtml = '';
                if (data.notifications.length > 0) {
                    data.notifications.forEach(notif => {
                        const isReadClass = notif.is_read ? '' : 'fw-bold';
                        const link = notif.link || 'dashboard.php';
                        const formattedDate = new Date(notif.created_at).toLocaleDateString('id-ID', {
                            day: '2-digit',
                            month: 'short',
                            year: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
                        });
                        dropdownHtml += `
                            <li>
                                <a class="dropdown-item notification-item ${isReadClass}"
                                   href="mark_notification_read.php?notification_id=${notif.id}&redirect_to=${encodeURIComponent(link)}">
                                    ${htmlspecialchars(notif.message)}
                                    <small>${formattedDate}</small>
                                </a>
                            </li>
                        `;
                    });
                    dropdownHtml += `<li><hr class="dropdown-divider"></li>
                                     <li><a class="dropdown-item text-center" href="notifications.php">Lihat Semua Notifikasi</a></li>`;
                } else {
                    dropdownHtml = `<li><a class="dropdown-item text-center" href="#">Tidak ada notifikasi baru.</a></li>`;
                }
                notificationDropdownMenu.innerHTML = dropdownHtml;
            }
        }

        function fetchNotifications() {
            fetch('../api/notifications.php')
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' + response.statusText);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        console.error('Error fetching notifications:', data.error);
                    } else {
                        updateNotificationsDisplay(data);
                    }
                })
                .catch(error => {
                    console.error('There was a problem with your fetch operation for notifications:', error);
                });
        }

        function htmlspecialchars(str) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return str.replace(/[&<>"']/g, function(m) {
                return map[m];
            });
        }

        fetchNotifications();
        setInterval(fetchNotifications, 30000); // Adjust interval as needed
    });
</script>


<script src="https://code.jquery.com/jquery-3.7.1.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/2.3.2/js/dataTables.js"></script>
<script src="https://cdn.datatables.net/2.3.2/js/dataTables.bootstrap5.js"></script>

<script>
    $.fn.dataTable.ext.errMode = 'none';

    new DataTable('#myTable', {
        searching: false,
        columnDefs: [{
                targets: 0,
                type: "string"
            } // Kolom ID di index ke-0
        ]
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const alertCell = document.querySelector('td.not-found');
        const alertBox = alertCell?.querySelector('.alert.alert-info.mb-0');

        if (alertBox && alertBox.offsetParent !== null) {
            document.querySelectorAll('td').forEach(td => {
                if (!td.classList.contains('not-found')) {
                    td.style.display = 'none';
                }
            });
        }
    });
</script>