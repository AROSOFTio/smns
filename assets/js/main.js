/**
 * Main Application JavaScript
 */

// Update time display every second (Vanilla JS - no jQuery dependency)
function updateDateTime() {
    var now = new Date();
    
    // Format time (hh:mm:ss AM/PM)
    var hours = now.getHours();
    var minutes = now.getMinutes();
    var seconds = now.getSeconds();
    var ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12; // 0 should be 12
    minutes = minutes < 10 ? '0' + minutes : minutes;
    seconds = seconds < 10 ? '0' + seconds : seconds;
    var timeString = hours + ':' + minutes + ':' + seconds + ' ' + ampm;
    
    // Format date (Day, Month Date, Year)
    var days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    var months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var dayName = days[now.getDay()];
    var monthName = months[now.getMonth()];
    var date = now.getDate();
    var year = now.getFullYear();
    var dateString = dayName + ', ' + monthName + ' ' + date + ', ' + year;
    
    // Update the display
    var timeElem = document.querySelector('#current-date-time .time-display');
    var dateElem = document.querySelector('#current-date-time .date-display');
    
    if (timeElem) timeElem.textContent = timeString;
    if (dateElem) dateElem.textContent = dateString;
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Update time immediately and then every second
    if (document.getElementById('current-date-time')) {
        updateDateTime();
        setInterval(updateDateTime, 1000);
    }
    
    // User dropdown toggle
    const userDropdown = document.querySelector('.user-dropdown');
    const dropdownToggle = document.getElementById('userDropdown');
    
    if (dropdownToggle) {
        dropdownToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            userDropdown.classList.toggle('active');
            // Close notification dropdown if open
            var nd = document.getElementById('notificationDropdown');
            if (nd) nd.classList.remove('show');
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!userDropdown.contains(e.target)) {
                userDropdown.classList.remove('active');
            }
        });
        
        // Close dropdown when clicking a menu item
        const dropdownItems = document.querySelectorAll('.dropdown-item');
        dropdownItems.forEach(item => {
            item.addEventListener('click', function() {
                userDropdown.classList.remove('active');
            });
        });
    }
    
    // Sidebar Toggle Functionality (single authoritative handler)
    const sidebarToggle = document.getElementById('sidebarToggle');

    // Notification Bell Toggle
    const notifBell = document.getElementById('notificationBell');
    const notifDropdown = document.getElementById('notificationDropdown');

    if (notifBell && notifDropdown) {
        notifBell.addEventListener('click', function(e) {
            e.stopPropagation();
            // Close user dropdown if open
            if (userDropdown) userDropdown.classList.remove('active');
            notifDropdown.classList.toggle('show');
        });

        // Close notification dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (notifDropdown && !notifDropdown.contains(e.target) && e.target !== notifBell) {
                notifDropdown.classList.remove('show');
            }
        });

        // Mark all as read
        const markAllBtn = document.getElementById('markAllRead');
        if (markAllBtn) {
            markAllBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                // Determine base URL from current page path
                var pathParts = window.location.pathname.split('/');
                var smnsIndex = pathParts.indexOf('smns');
                var baseUrl = pathParts.slice(0, smnsIndex + 1).join('/');

                fetch(baseUrl + '/api/notifications.php?action=mark_all_read', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success) {
                        // Remove badge
                        var badge = notifBell.querySelector('.notification-badge');
                        if (badge) badge.remove();
                        // Clear unread styling
                        document.querySelectorAll('.notification-item.unread').forEach(function(item) {
                            item.classList.remove('unread');
                        });
                        // Replace list with empty state
                        var list = document.querySelector('.notification-list');
                        if (list) {
                            list.innerHTML = '<div class="notification-empty"><i class="fas fa-bell-slash"></i><p>No new notifications</p></div>';
                        }
                        // Hide mark all link
                        markAllBtn.style.display = 'none';
                    }
                })
                .catch(function(err) { console.error('Notification error:', err); });
            });
        }

        // Mark individual notification as read on click
        document.querySelectorAll('.notification-item').forEach(function(item) {
            item.addEventListener('click', function() {
                var notifId = this.getAttribute('data-id');
                if (notifId) {
                    var pathParts = window.location.pathname.split('/');
                    var smnsIndex = pathParts.indexOf('smns');
                    var baseUrl = pathParts.slice(0, smnsIndex + 1).join('/');

                    fetch(baseUrl + '/api/notifications.php?action=mark_read&id=' + notifId, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' }
                    }).catch(function(err) { console.error('Notification error:', err); });
                }
            });
        });
    }
    const sidebar = document.getElementById('sidebar') || document.querySelector('.sidebar');
    const mainContent = document.getElementById('mainContent') || document.querySelector('.main-content');
    
    if (sidebarToggle && sidebar && mainContent) {
        // Clean up old localStorage key from navigation.js
        localStorage.removeItem('sidebar-collapsed');
        
        // Check localStorage for sidebar state and apply on page load
        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
            var icon = sidebarToggle.querySelector('i');
            if (icon) {
                icon.classList.remove('fa-bars');
                icon.classList.add('fa-indent');
            }
        }
        
        sidebarToggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            
            // Save state to localStorage
            var isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
            
            // Change icon
            var icon = this.querySelector('i');
            if (icon) {
                if (isCollapsed) {
                    icon.classList.remove('fa-bars');
                    icon.classList.add('fa-indent');
                } else {
                    icon.classList.remove('fa-indent');
                    icon.classList.add('fa-bars');
                }
            }
        });
    }
});

// jQuery-dependent features
$(document).ready(function() {
    // Initialize tooltips if Bootstrap is available
    if (typeof $().tooltip === 'function') {
        $('[data-toggle="tooltip"]').tooltip();
    }
    
    // Global alert auto-dismiss is handled in navigation.js with a longer timeout.
    
    // Confirm delete actions
    $('.delete-btn, .btn-danger[data-confirm]').click(function(e) {
        if (!confirm('Are you sure you want to delete this item? This action cannot be undone.')) {
            e.preventDefault();
            return false;
        }
    });
    
    // Form validation
    $('form[data-validate]').submit(function(e) {
        var valid = true;
        $(this).find('input[required], select[required], textarea[required]').each(function() {
            if (!$(this).val()) {
                $(this).addClass('is-invalid');
                valid = false;
            } else {
                $(this).removeClass('is-invalid');
            }
        });
        
        if (!valid) {
            e.preventDefault();
            alert('Please fill in all required fields');
            return false;
        }
    });
    
    // Remove invalid class on input
    $('input, select, textarea').on('change keyup', function() {
        if ($(this).val()) {
            $(this).removeClass('is-invalid');
        }
    });
    
    // Sidebar toggle for mobile is handled by vanilla JS above - no duplicate jQuery handler
    
    // DataTable initialization if available
    if (typeof $.fn.DataTable === 'function') {
        $('.data-table').DataTable({
            "pageLength": 20,
            "ordering": true,
            "searching": true,
            "responsive": true
        });
    }
    
    // Print button
    $('.print-btn').click(function(e) {
        e.preventDefault();
        window.print();
    });
    
    // Export to CSV
    $('.export-csv').click(function(e) {
        e.preventDefault();
        var table = $(this).closest('.card').find('table');
        var csv = [];
        
        // Get headers
        var headers = [];
        table.find('thead th').each(function() {
            headers.push($(this).text());
        });
        csv.push(headers.join(','));
        
        // Get rows
        table.find('tbody tr').each(function() {
            var row = [];
            $(this).find('td').each(function() {
                row.push('"' + $(this).text().replace(/"/g, '""') + '"');
            });
            csv.push(row.join(','));
        });
        
        // Download
        var csvContent = csv.join('\n');
        var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'export_' + new Date().getTime() + '.csv';
        link.click();
    });
});

// Number formatting
function formatNumber(num, decimals = 0) {
    return parseFloat(num).toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

// Currency formatting
function formatCurrency(amount) {
    return '$' + formatNumber(amount, 2);
}

// Show loading spinner
function showLoading() {
    $('body').append('<div class="loading-overlay"><div class="spinner"></div></div>');
}

// Hide loading spinner
function hideLoading() {
    $('.loading-overlay').remove();
}

// AJAX error handler
$(document).ajaxError(function(event, jqxhr, settings, thrownError) {
    console.error('AJAX Error:', thrownError);
    hideLoading();
    alert('An error occurred. Please try again.');
});

// Success message
function showSuccess(message) {
    // persistent success alert — admin must dismiss manually
    var alert = $('<div class="alert alert-success" data-auto-dismiss="false">' + message + '</div>');
    $('.content-area').prepend(alert);
    // do NOT auto-dismiss; user will close when ready
}

// Error message
function showError(message) {
    var alert = $('<div class="alert alert-danger">' + message + '</div>');
    $('.content-area').prepend(alert);
    setTimeout(function() {
        alert.fadeOut('slow', function() {
            $(this).remove();
        });
    }, 20000);
}
