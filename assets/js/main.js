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
});

// jQuery-dependent features
$(document).ready(function() {
    // Initialize tooltips if Bootstrap is available
    if (typeof $().tooltip === 'function') {
        $('[data-toggle="tooltip"]').tooltip();
    }
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);
    
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
    
    // Sidebar toggle for mobile
    $('#sidebarToggle').click(function() {
        $('.sidebar').toggleClass('show');
    });
    
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
    var alert = $('<div class="alert alert-success">' + message + '</div>');
    $('.content-area').prepend(alert);
    setTimeout(function() {
        alert.fadeOut('slow', function() {
            $(this).remove();
        });
    }, 3000);
}

// Error message
function showError(message) {
    var alert = $('<div class="alert alert-danger">' + message + '</div>');
    $('.content-area').prepend(alert);
    setTimeout(function() {
        alert.fadeOut('slow', function() {
            $(this).remove();
        });
    }, 5000);
}
